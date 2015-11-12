<?php

class ISY extends Module {

    private $programs;

    const CALL_TIMEOUT_S = 10;

    public function initialize()
    {
        // cache programs
        $this->programs = $this->getPrograms();
        if (!$this->isyPrograms) {
            $this->log("ISY PROGRAMS UNKNOWN");
            return;
        }
        $this->log("ISY PROGRAMS DISCOVERED: " . count($this->programs));
    }

    public function occupied()
    {
        $occupiedProgramId = $this->programs[$this->configuration->occupiedProgram];
        $this->runProgram($occupiedProgramId);
    }

    public function entered()
    {
        $enteredProgramId = $this->programs[$this->configuration->enteredProgram];
        $this->runProgram($enteredProgramId);
    }

    public function vacant()
    {
        $vacantProgramId = $this->programs[$this->configuration->vacantProgram];
        $this->runProgram($vacantProgramId);
    }

    private function runProgram($id)
    {
        $this->call("programs/$id/run");
    }

    private function getPrograms()
    {

        $response = $this->call("programs?subfolders=true");
        if (!$response) {
            return null;
        }

        $names = $response->xpath("/programs/program/name/text()");
        $ids = $response->xpath("/programs/program/@id");
        $ids = array_map(function($id) {
            return (string) $id[0];
        }, $ids);
        return array_combine($names, $ids);

    }

    private function call($rest)
    {
        $url = "http://" . $this->configuration->hostname . "/rest/" . $rest;
        $request = curl_init($url);
        curl_setopt($request, CURLOPT_HEADER, 1);
        curl_setopt($request, CURLOPT_USERPWD, $this->configuration->username . ":" . $this->configuration->password);
        curl_setopt($request, CURLOPT_TIMEOUT, self::CALL_TIMEOUT_S);
        curl_setopt($request, CURLOPT_RETURNTRANSFER, TRUE);
        $response = curl_exec($request);
        $headerSize = curl_getinfo($request, CURLINFO_HEADER_SIZE);
        $body = substr($response, $headerSize);
        $error = curl_errno($request);
        curl_close($request);
        // check for error
        if ($error) {
            return null;
        } else {
            return simplexml_load_string($body);
        }
    }

}