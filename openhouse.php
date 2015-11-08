<?php

class OpenHouse {


    private $foundDevices = [];
    private $registeredDevices;
    private $isyHostname;
    private $isyUsername;
    private $isyPassword;
    private $isyOccupiedProgram;
    private $isyEmptyProgram;
    private $isyEnteredProgram;
    private $isyPrograms;
    private $houseOccupied;

    const DEVICE_TIMEOUT_S = 120;
    const HCI_PAGETO_MS = 750;
    const NAME_DEVICE_REPEAT = 2;

    function __construct($config)
    {
        $this->registeredDevices = $config->registeredDevices;
        $this->isyHostname = $config->isyHostname;
        $this->isyUsername = $config->isyUsername;
        $this->isyPassword = $config->isyPassword;
        $this->isyOccupiedProgram = $config->isyOccupiedProgram;
        $this->isyEmptyProgram = $config->isyEmptyProgram;
        $this->isyEnteredProgram = $config->isyEnteredProgram;
    }

    private function hciConfig()
    {
        // ensure BT is up
        shell_exec("hciconfig hci0 up");
        // set ping HW timeout
        shell_exec("hciconfig hci0 pageto " . self::HCI_PAGETO_MS);
        $this->log("HCI CONFIGURED");
    }

    private function nameDevices()
    {

        $command = '';
        foreach ($this->registeredDevices as $registeredDevice) {
            for ($i = 0; $i < self::NAME_DEVICE_REPEAT; $i++) {
                $command .= "hcitool name $registeredDevice->address;";
            }
        }

        $result = shell_exec($command);

        $deviceNames = [];
        foreach ($this->registeredDevices as $registeredDevice) {
            if (strpos($result, $registeredDevice->name) !== false) {
                $deviceNames[] = $registeredDevice->name;
            }
        }

        return $deviceNames;

    }

    private function runIsyOccupiedProgram()
    {
        $isyOccupiedProgramId = $this->isyPrograms[$this->isyOccupiedProgram];
        $this->runIsyProgram($isyOccupiedProgramId);
    }

    private function runIsyEnteredProgram()
    {
        $isyEnteredProgramId = $this->isyPrograms[$this->isyEnteredProgram];
        $this->runIsyProgram($isyEnteredProgramId);
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

    private function log($message) {
        echo date('Y-m-d H:i:s') . ' ' . $message . "\n";
    }

    public function run()
    {

        // hci up and config
        $this->hciConfig();
        // cache program ids
        $this->isyPrograms = $this->getIsyPrograms();
        $this->log("ISY PROGRAMS DISCOVERED: " . count($this->isyPrograms));

        while (true) {

            // find all registered devices nearby
            $foundDeviceNames = $this->nameDevices();
            
            foreach ($this->registeredDevices as $registeredDevice) {

                if (array_search($registeredDevice->name, $foundDeviceNames) !== false) {

                    $this->log("DEVICE ALIVE: $registeredDevice->name ($registeredDevice->address)");

                    if (!array_key_exists($registeredDevice->name, $this->foundDevices)) {
                        $this->log("DEVICE ENTERED: $registeredDevice->name ($registeredDevice->address)");
                        $this->runIsyEnteredProgram();
                    }

                    if (count($this->foundDevices) === 0) {
                        $this->log("HOUSE OCCUPIED");
                        $this->houseOccupied = true;
                        $this->runIsyOccupiedProgram();
                    }

                    // update the time of discovery
                    $this->foundDevices[$registeredDevice->name] = time();

                } else {

                    $this->log("DEVICE RELAXING: $registeredDevice->name ($registeredDevice->address)");

                    // see if an existing entry has expired
                    if (array_key_exists($registeredDevice->name, $this->foundDevices)) {
                        if ((time() - $this->foundDevices[$registeredDevice->name]) > self::DEVICE_TIMEOUT_S) {
                            $this->log("DEVICE LOST: $registeredDevice->name ($registeredDevice->address)");
                            unset($this->foundDevices[$registeredDevice->name]);
                            if (count($this->foundDevices) === 0) {
                                $this->log("HOUSE EMPTY");
                                $this->houseOccupied = false;
                                $this->runIsyEmptyProgram();
                            }
                        }
                    }

                }

            }

        }
    }

}

// check for config file
if (count($argv) < 2) {
    exit(1);
}

$configString = file_get_contents($argv[1]);
$config = json_decode($configString);
$openHouse = new OpenHouse($config);
$openHouse->run();
