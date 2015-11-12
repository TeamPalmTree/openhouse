<?php

class OpenHouse extends Module {

    private $modules = [];
    private $foundDevices = [];
    private $isOccupied;

    const DEVICE_TIMEOUT_S = 300;
    const HCI_PAGETO_MS = 1000;
    const NAME_DEVICE_REPEAT = 1;

    private function loadModules()
    {
        foreach ($this->configuration->modules as $moduleName => $moduleConfiguration) {
            require "modules/$moduleName.php";
            $module = new $moduleName($moduleConfiguration);
            $module->initialize();
            $this->modules[$moduleName] = $module;
        }
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
        foreach ($this->configuration->registeredDevices as $registeredDevice) {
            for ($i = 0; $i < self::NAME_DEVICE_REPEAT; $i++) {
                $command .= "hcitool name $registeredDevice->address;";
            }
        }

        $result = shell_exec($command);

        $deviceNames = [];
        foreach ($this->configuration->registeredDevices as $registeredDevice) {
            if (strpos($result, $registeredDevice->name) !== false) {
                $deviceNames[] = $registeredDevice->name;
            }
        }

        return $deviceNames;

    }

    public function entered()
    {
        foreach ($this->modules as $module) {
            $module->entered();
        }
    }

    public function occupied()
    {
        foreach ($this->modules as $module) {
            $module->occupied();
        }
    }

    public function vacant()
    {
        foreach ($this->modules as $module) {
            $module->vacant();
        }
    }

    public function initialize()
    {

        // load all app submodules
        $this->loadModules();
        // hci up and config
        $this->hciConfig();

        while (true) {

            // find all registered devices nearby
            $foundDeviceNames = $this->nameDevices();
            
            foreach ($this->configuration->registeredDevices as $registeredDevice) {

                if (array_search($registeredDevice->name, $foundDeviceNames) !== false) {

                    $this->log("DEVICE ALIVE: $registeredDevice->name ($registeredDevice->address)");

                    if (!array_key_exists($registeredDevice->name, $this->foundDevices)) {
                        $this->log("DEVICE ENTERED: $registeredDevice->name ($registeredDevice->address)");
                        $this->entered();
                    }

                    if (count($this->foundDevices) === 0) {
                        $this->log("HOUSE OCCUPIED");
                        $this->isOccupied = true;
                        $this->occupied();
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
                                $this->isOccupied = false;
                                $this->empty();
                            }
                        }
                    }

                }

            }

        }
    }

}

