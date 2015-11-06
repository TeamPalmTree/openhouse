<?php

class OpenHouse {

    private $foundAddresses = [];
    private $registeredAddresses;
    private $isyHostname;
    private $isyUsername;
    private $isyPassword;
    private $isyOccupiedProgram;
    private $isyEmptyProgram;

    private $isyPrograms;

    const DEVICE_TIMEOUT = 30;
    const L2PING_SUCCESS = '1 sent, 1 received';

    function __construct($config)
    {
        $this->registeredAddresses = $config->registeredAddresses;
        $this->isyHostname = $config->isyHostname;
        $this->isyUsername = $config->isyUsername;
        $this->isyPassword = $config->isyPassword;
        $this->isyOccupiedProgram = $config->isyOccupiedProgram;
        $this->isyEmptyProgram = $config->isyEmptyProgram;
    }

    private function pingRegisteredDevices()
    {
        $command = '';
        foreach ($this->registeredAddresses as $registeredAddress) {
            $command .= "l2ping $registeredAddress -c 1 & ";
        }
        $command .= 'wait';
        $result = exec($command);
        $pungDevices = [];
        foreach ($this->registeredAddresses as $registeredAddress) {
            if (strpos($result, self::L2PING_SUCCESS) !== false) {
                $pungDevices[] = $registeredAddress;
            }
        }
        return $pungDevices;
    }
    private function runIsyOccupiedProgram()
    {
        $isyOccupiedProgramId = $this->isyPrograms[$this->isyOccupiedProgram];
        $this->runIsyProgram($isyOccupiedProgramId);
    }

    private function runIsyEmptyProgram()
    {
        $isyEmptyProgramId = $this->isyPrograms[$this->isyEmptyProgram];
        $this->runIsyProgram($isyEmptyProgramId);
    }

    private function runIsyProgram($programId)
    {
        $this->callIsy("programs/$programId/run");
    }

    private function getIsyPrograms()
    {
        $response = $this->callIsy("programs?subfolders=true");
        $names = $response->xpath("/programs/program/name/text()");
        $ids = $response->xpath("/programs/program/@id");
        $ids = array_map(function($id) {
            return (string) $id[0];
        }, $ids);
        return array_combine($names, $ids);
    }

    private function callIsy($rest)
    {
        $url = "http://" . $this->isyHostname . "/rest/" . $rest;
        $request = curl_init($url);
        curl_setopt($request, CURLOPT_HEADER, 1);
        curl_setopt($request, CURLOPT_USERPWD, $this->isyUsername . ":" . $this->isyPassword);
        curl_setopt($request, CURLOPT_TIMEOUT, 30);
        curl_setopt($request, CURLOPT_RETURNTRANSFER, TRUE);
        $response = curl_exec($request);
        $headerSize = curl_getinfo($request, CURLINFO_HEADER_SIZE);
        $body = substr($response, $headerSize);
        curl_close($request);
        return simplexml_load_string($body);
    }

    public function run()
    {

        // cache program ids
        $this->isyPrograms = $this->getIsyPrograms();

        while (true) {

            // ping all devices simultaneously
            $pungDevices = $this->pingRegisteredDevices();
            
            foreach ($this->registeredAddresses as $registeredAddress) {

                if (array_search($registeredAddress, $pungDevices) !== false) {

                    if (!array_key_exists($registeredAddress, $this->foundAddresses)) {
                        echo "DEVICE DISCOVERED: $registeredAddress\n";
                    }

                    if (count($this->foundAddresses) === 0) {
                        echo "HOUSE OCCUPIED\n";
                        $this->runIsyOccupiedProgram();
                    }

                    // update the time of discovery
                    $this->foundAddresses[$registeredAddress] = time();

                } else {

                    // see if an existing entry has expired
                    if (array_key_exists($registeredAddress, $this->foundAddresses)) {
                        if ((time() - $this->foundAddresses[$registeredAddress]) > self::DEVICE_TIMEOUT) {
                            echo "DEVICE LOST: $registeredAddress\n";
                            unset($this->foundAddresses[$registeredAddress]);
                            if (count($this->foundAddresses) === 0) {
                                echo "HOUSE EMPTY\n";
                                $this->runIsyEmptyProgram();
                            }
                        }
                    }

                }

            }

        }
    }

}

$configString = file_get_contents("config.json");
$config = json_decode($configString);
$openHouse = new OpenHouse($config);
$openHouse->run();
