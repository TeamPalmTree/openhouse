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

    const BLUETOOTHCTL_NAME = 'Name: ';
    const BLUETOOTHCTL_CONNECTED = 'Connected: yes';

    const POLL_DELAY = 2;
    const DEVICE_TIMEOUT = 30;

    function __construct($config)
    {
        $this->registeredAddresses = $config->registeredAddresses;
        $this->isyHostname = $config->isyHostname;
        $this->isyUsername = $config->isyUsername;
        $this->isyPassword = $config->isyPassword;
        $this->isyOccupiedProgram = $config->isyOccupiedProgram;
        $this->isyEmptyProgram = $config->isyEmptyProgram;
    }

    private function getDevice($address)
    {

        $connected = false;
        $command = "echo \"info $address\" | bluetoothctl";
        exec($command, $lines);
        foreach ($lines as $line) {
            $namePosition = strpos($line, self::BLUETOOTHCTL_NAME);
            if ($namePosition !== false) {
                $name = substr($line, $namePosition + strlen(self::BLUETOOTHCTL_NAME));
            } elseif (strpos($line, self::BLUETOOTHCTL_CONNECTED) !== false) {
                $connected = true;
            }
        }

        // if we couldn't find a name, assume this failed
        if (!isset($name)) {
            return false;
        }

        return new OpenHouseDevice($name, $address, $connected);

    }

    private function connectToDevice($address)
    {
        $command = "echo \"connect $address\" | bluetoothctl";
        exec($command);
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
        print_r($url);
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
        print_r($this->isyPrograms);

        while (true) {
            
            foreach ($this->registeredAddresses as $registeredAddress) {

                $device = $this->getDevice($registeredAddress);
                print_r($device);
                if (!$device) {
                    echo "DEVICE UNPAIRED: $registeredAddress\n";
                    continue;
                }

                if ($device->getConnected()) {

                    if (!array_key_exists($registeredAddress, $this->foundAddresses)) {
                        echo "DEVICE DISCOVERED: " . $device->getName() . "\n";
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
                            echo "DEVICE LOST: " . $device->getName() . "\n";
                            unset($this->foundAddresses[$registeredAddress]);
                            if (count($this->foundAddresses) === 0) {
                                echo "HOUSE EMPTY\n";
                                $this->runIsyEmptyProgram();
                            }
                        }
                    }

                    // attempt to connect to the device
                    $this->connectToDevice($registeredAddress);

                }

            }

            sleep(self::POLL_DELAY);

        }
    }

}

class OpenHouseDevice {

    private $name;
    private $address;
    private $connected;

    public function getName() {
        return $this->name;
    }

    public function getAddress() {
        return $this->address;
    }

    public function getConnected() {
        return $this->connected;
    }

    function __construct($name, $address, $connected) {
        $this->name = $name;
        $this->address = $address;
        $this->connected = $connected;
    }

}

$configString = file_get_contents("config.json");
$config = json_decode($configString);
$openHouse = new OpenHouse($config);
$openHouse->run();
