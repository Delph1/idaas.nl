<?php

/**
 * Calls Openwhisk apis. See https://console.bluemix.net/apidocs/functions
 */

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Exception\RequestException;
use App\Exceptions\CloudFunctionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\StandardMail;
use ArieTimmerman\Laravel\SCIMServer\Helper;
use ArieTimmerman\Laravel\SCIMServer\ResourceType;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;

class CloudFunctionHelper
{
    public static function getActionUrl(CloudFunction $cloudFunction)
    {
        return sprintf(
            'https://%s/api/v1/namespaces/_/actions/%s?overwrite=true',
            config('serverless.openwhisk_host'),
            \urlencode($cloudFunction->name)
        );
    }

    public static function deploy(CloudFunction $cloudFunction)
    {
        $response = null;
        $guzzle = new Client();

        // check: https://console.bluemix.net/apidocs/functions
        //TODO: set timeout on Guzzle client to 12 seconds?? What if timeout occurs? Continue?
        $guzzle = new Client();

        // /home/arie/git/ice-complete/ice/resources/serverless/digitalocean/example

        // File::copyDirectory(resource_path('serverless/digitalocean'), '/tmp/');

        $zip = new \ZipArchive();

        if ($zip->open('/dev/shm/test.zip', \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Cannot open memory');
        }

        $path = resource_path('serverless/digitalocean');

        $files = File::allFiles($path);
        foreach ($files as $path) {
            $zip->addFile($path, $path->getFilename());
        }

        //serverless/example123
        $zip->addFromString('packages/sample/hello/hello.js', $cloudFunction->getDeployableCode());
        $zip->close();


        $contents = file_get_contents('/dev/shm/test.zip');
        $result = base64_encode($contents);

        $response = $guzzle->request(
            'PUT',
            self::getActionUrl($cloudFunction),
            [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Basic ' . \base64_encode(config('serverless.openwhisk_api_key'))
                ],
                RequestOptions::JSON => [

                    "namespace" => "_",

                    "exec" => [

                        "kind" => "nodejs:16",
                        "binary" => true,
                        "code" => $result,

                        "components" => [
                            // action names
                        ]
                    ],

                    "limits" => [
                        "timeout" => 6000, // in miliseconds,
                        "memory" => 128, // in MB
                        "logs" => 10, // in MB
                    ],
                ]
            ]
        );

        return $response;
    }

    public static function handle($result)
    {
        Log::debug($result['results'] ?? null);

        $results = $result['results'] ?? [];

        foreach ($results as $result) {
            if (isset($result['type']) && $result['type'] == 'mail' && isset($result['to']) && $result['template_id']) {
                $to = null;

                // complete user object
                if (is_array($result['to'])) {
                    $to = @$result['to']['urn:ietf:params:scim:schemas:core:2.0:User']['emails'][0]['value'] ?? null;
                } elseif (filter_var($result['to'], FILTER_VALIDATE_EMAIL)) {
                    $to = $result['to'];
                } elseif (Uuid::isValid($result['to'])) {
                    $to = User::find($result['to'])->value('email');
                }

                Mail::to($to)->cc($result['cc'] ?? [])->send(
                    new StandardMail($result['template_id'], $result['data'] ?? [], EmailTemplate::TYPE_ACTIVATION)
                );
            }
        }
    }

    public static function invoke(CloudFunction $cloudFunction, $parameters = [])
    {
        if ($cloudFunction->needsDeploy()) {
            self::deploy($cloudFunction);
        }

        $parameters['variables'] = [];

        foreach ($cloudFunction->getMembers() as $member) {
            $counts = [];

            $parameters['variables'][$member->id] = collect($member->variables)->mapWithKeys(
                function ($value) use (&$counts) {
                    $result = null;

                    switch ($value['type']) {
                        case "EmailTemplate":
                            $result = $value['id'];
                            break;
                        case "User":
                            $result = Helper::objectToSCIMArray(User::find($value['id']), ResourceType::user());
                            break;
                        case "Group":
                            $result = Helper::objectToSCIMArray(Group::find($value['id']));
                            break;
                    }

                    if (!array_key_exists($value['type'], $counts)) {
                        $counts[$value['type']] = 0;
                    }

                    return [\lcfirst($value['type']) . ($counts[$value['type']]++) => $result];
                }
            )->toArray();
        }

        $actionUrl = sprintf(
            'https://%s/api/v1/namespaces/_/actions/%s?blocking=true&result=true',
            config('serverless.openwhisk_host'),
            \urlencode($cloudFunction->name)
        );
        $triedDeployment = false;
        $success = false;
        $guzzle = new Client();

        do {
            try {
                $response = $guzzle->request(
                    'POST',
                    $actionUrl,
                    [
                        RequestOptions::HEADERS        => [
                            'Authorization' => 'Basic ' . \base64_encode(config('serverless.openwhisk_api_key')),
                            'Content-Type' => 'application/json'
                        ],

                        // do NOT force_object, since this convers real arrays (with numeric indexes) to objects
                        RequestOptions::BODY => json_encode($parameters, JSON_FORCE_OBJECT)
                    ]
                );


                $success = true;
            } catch (RequestException $e) {
                $response = $e->getResponse();

                if ($response == null) {
                    throw $e;
                }

                if ($response->getStatusCode() == '404') {
                    self::deploy($cloudFunction);
                    $triedDeployment = true;
                } elseif ($response->getStatusCode() == '502') {
                    throw new CloudFunctionException((string) $response->getBody());
                } else {
                    throw $e;
                }
            }
        } while (!$triedDeployment && !$success);

        $cloudFunction->run_at = \Carbon\Carbon::now();
        $cloudFunction->save();

        $result = json_decode((string) $response->getBody(), true);

        return $result;
    }
}
