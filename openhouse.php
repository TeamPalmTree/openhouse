<?php

class OpenHouse {

    private $foundAddresses = [];
    private $registeredAddresses;
    private $isyHostname;
    private $isyUsername;
    private $isyPassword;
    private $isyOccupiedProgram;
    private $isyEmptyProgram;
    private $isyEnteredProgram;
    private $isyPrograms;
    private $houseOccupied;

    const STARTUP_COMMAND_DELAY_S = 5;
    const PAIRING_ATTEMPTS = 3;
    const DEVICE_TIMEOUT_S = 30;
    const OCCUPIED_POLL_DELAY_S = 2;
    const HCI_PAGETO_MS = 1500;
    const L2PING_TIMEOUT = 1;

    function __construct($config)
    {
        $this->registeredAddresses = $config->registeredAddresses;
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
        echo "HCI CONFIGURED\n";
        // small wait
        sleep(self::STARTUP_COMMAND_DELAY_S);
    }

    private function hciPair()
    {
        foreach ($this->registeredAddresses as $registeredAddress) {
            for ($attempts = 1; $attempts <= self::PAIRING_ATTEMPTS; $attempts++) {

                // attempt to pair with registered addresses
                $result = shell_exec("hcitool cc $registeredAddress; hcitool auth $registeredAddress;");
                if (strpos($result, 'error') === false) {
                    echo "DEVICE PAIRED: $registeredAddress\n";
                    break;
                } else {
                    echo "DEVICE NOT PAIRED $registeredAddress (ATTEMPT: $attempts/" . self::PAIRING_ATTEMPTS . ")\n";
                }

                // small wait
                sleep(self::STARTUP_COMMAND_DELAY_S);

            }
        }
    }

    private function pingDevice($address)
    {
        $result = shell_exec("l2ping $address -c 1 -t " . self::L2PING_TIMEOUT);
        return (strpos($result, $address) !== false);
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
        echo "ISY PROGRAMS DISCOVERED: " . count($ids) . "\n";
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

        // hci up and config
        $this->hciConfig();
        // pair devices
        $this->hciPair();
        // cache program ids
        $this->isyPrograms = $this->getIsyPrograms();

        while (true) {
            
            foreach ($this->registeredAddresses as $registeredAddress) {

                if ($this->pingDevice($registeredAddress)) {

                    if (!array_key_exists($registeredAddress, $this->foundAddresses)) {
                        echo "DEVICE ENTERED: $registeredAddress\n";
                        $this->runIsyEnteredProgram();
                    }

                    if (count($this->foundAddresses) === 0) {
                        echo "HOUSE OCCUPIED\n";
                        $this->houseOccupied = true;
                        $this->runIsyOccupiedProgram();
                    }

                    // update the time of discovery
                    $this->foundAddresses[$registeredAddress] = time();

                } else {

                    // see if an existing entry has expired
                    if (array_key_exists($registeredAddress, $this->foundAddresses)) {
                        if ((time() - $this->foundAddresses[$registeredAddress]) > self::DEVICE_TIMEOUT_S) {
                            echo "DEVICE LOST: $registeredAddress\n";
                            unset($this->foundAddresses[$registeredAddress]);
                            if (count($this->foundAddresses) === 0) {
                                echo "HOUSE EMPTY\n";
                                $this->houseOccupied = false;
                                $this->runIsyEmptyProgram();
                            }
                        }
                    }

                }

                // if we are occupied, we can take a breather
                if ($this->houseOccupied) {
                    sleep(self::OCCUPIED_POLL_DELAY_S);
                }

            }

        }
    }

}

$configString = file_get_contents("config.json");
$config = json_decode($configString);
$openHouse = new OpenHouse($config);
$openHouse->run();
