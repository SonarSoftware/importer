<?php

namespace SonarSoftware\Importer;

use Exception;
use InvalidArgumentException;
use GuzzleHttp\Exception\ClientException;
use SonarSoftware\Importer\Extenders\AccessesSonar;;

class AccountServiceImporter extends AccessesSonar
{
    /**
     * @param $pathToImportFile
     * @return array
     */
    public function import($pathToImportFile)
    {
        if (($handle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $this->loadServiceData();
            $this->validateImportFile($pathToImportFile);

            $failureLogName = tempnam(getcwd() . "/log_output","account_service_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(getcwd() . "/log_output","account_service_import_successes");
            $successLog = fopen($successLogName,"w");

            $returnData = [
                'successes' => 0,
                'failures' => 0,
                'failure_log_name' => $failureLogName,
                'success_log_name' => $successLogName,
            ];

            $row = 0;
            while (($data = fgetcsv($handle, 8096, ",")) !== FALSE) {
                $row++;
                try {
                    $this->addServiceToAccount($data);
                }
                catch (ClientException $e)
                {
                    $response = $e->getResponse();
                    $body = json_decode($response->getBody());
                    $returnMessage = implode(", ",(array)$body->error->message);
                    fputcsv($failureLog,array_merge($data,$returnMessage));
                    $returnData['failures'] += 1;
                    continue;
                }
                catch (Exception $e)
                {
                    fputcsv($failureLog,array_merge($data,$e->getMessage()));
                    $returnData['failures'] += 1;
                    continue;
                }

                $returnData['successes'] += 1;
                fwrite($successLog,"Row $row succeeded for account ID " . trim($data[0]) . "\n");
            }
        }
        else
        {
            throw new InvalidArgumentException("File could not be opened.");
        }

        fclose($failureLog);
        fclose($successLog);

        return $returnData;
    }

    /**
     * Load service data into a private var.
     */
    private function loadServiceData()
    {
        $serviceArray = [];

        $response = $this->client->get($this->uri . "/api/v1/system/services", [
            'headers' => [
                'Content-Type' => 'application/json; charset=UTF8',
                'timeout' => 30,
            ],
            'auth' => [
                $this->username,
                $this->password,
            ]
        ]);

        $objResponse = json_decode($response->getBody());
        foreach ($objResponse->data as $datum)
        {
            if ($datum->type == "recurring" || $datum->type == "expiring")
            {
                $serviceArray[$datum->id] = [
                    'type' => $datum->type,
                    'application' => $datum->application,
                ];
            }
        }

        while ($objResponse->paginator->current_page != $objResponse->paginator->total_pages)
        {
            $response = $this->client->get($this->uri . "/api/v1/system/services", [
                'headers' => [
                    'Content-Type' => 'application/json; charset=UTF8',
                    'timeout' => 30,
                ],
                'auth' => [
                    $this->username,
                    $this->password,
                ]
            ]);

            $objResponse = json_decode($response->getBody());
            foreach ($objResponse->data as $datum)
            {
                if ($datum->type == "recurring" || $datum->type == "expiring")
                {
                    $serviceArray[$datum->id] = [
                        'type' => $datum->type,
                        'application' => $datum->application,
                    ];
                }
            }
        }

        $this->services = $serviceArray;
    }

    /**
     * @param $pathToImportFile
     */
    private function validateImportFile($pathToImportFile)
    {
        $requiredColumns = [ 0,1 ];

        if (($fileHandle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $row = 0;
            while (($data = fgetcsv($fileHandle, 8096, ",")) !== FALSE) {
                $row++;
                foreach ($requiredColumns as $colNumber) {
                    if (trim($data[$colNumber]) == '') {
                        throw new InvalidArgumentException("In the account service import, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
                    }
                }

                if (!array_key_exists($data[1],$this->services))
                {
                    throw new InvalidArgumentException("Service ID {$data[1]} is not a recurring or expiring service.");
                }

                if (trim($data[2]))
                {
                    if (!is_numeric($data[2]))
                    {
                        throw new InvalidArgumentException("Price override on row $row is not numeric.");
                    }
                }
            }
        }
        else
        {
            throw new InvalidArgumentException("Could not open import file.");
        }

        return;
    }

    /**
     * @param $data
     * @return array
     */
    private function buildPayload($data)
    {
        $payload = [
            'service_id' => (int)trim($data[1]),
            'prorate' => false
        ];

        if ($data[2])
        {
            $payload['price_override'] = (float)trim($data[2]);
            $payload['price_override_reason'] = trim($data[3]) ? trim($data[3]) : 'Unknown';
        }

        return $payload;
    }

    /**
     * Add service to account
     * @param $data
     * @return mixed
     */
    private function addServiceToAccount($data)
    {
        $payload = $this->buildPayload($data);

        $accountID = (int)trim($data[0]);

        return $this->client->post($this->uri . "/api/v1/accounts/$accountID/services", [
            'headers' => [
                'Content-Type' => 'application/json; charset=UTF8',
                'timeout' => 30,
            ],
            'auth' => [
                $this->username,
                $this->password,
            ],
            'json' => $payload,
        ]);
    }
}