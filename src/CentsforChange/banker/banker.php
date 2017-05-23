<?php

/*
* This file is part of the banker package from Cents for Change
*
* (c) Cents for Change, L.L.C. <erik@centsforchange.net>
*
* For full copyright and license info, please view the LICENSE file containing your copy of the Apache 2.0 license provided with this file.
*/

namespace CentsforChange\Banker;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;


class Banker{

    private $fid;

    private $org;

    private $url;

    private $bankId; //This is routing number for bank accounts, empty otherwise

    private $user;

    private $password;

    private $accountId; //Account number

    private $accountType; 

    private $clientId;

    private $appVersion;

    private $ofxVersion;

    private $app;

    //Starts at 3, don't ask me why but it works
    private $cookie = 3;

    private function nextCookie(){
        $this->cookie = $this->cookie + 1;
        return (string) $this->cookie;
    }

    public function __constructor($fid, $org, $url, $user, $password, $accountType, $clientId, $accountId, $bankId = "", $appVersion = "2500", $ofxVersion = "102", $app = "QWIN"){
        $this->fid = $fid;
        $this->org = $org;
        $this->url = $url;
        $this->user = $user;
        $this->password = $password;
        $this->accountType = strtoupper($accountType);
        $this->clientId = $clientId;
        $this->accountId = $accountId;
        $this->bankId = $bankId;
        $this->appVersion = $appVersion;
        $this->ofxVersion = $ofxVersion;
        $this->app = $app;
    }

    private function uuid($length){
        //Generate a bunch of bytes to be sure we've got enough, substring it
        return substr(base_convert(random_bytes($length * 8), 8, 36), -1 * $length);
    }
    
    private function signOnMessage(){
        $baseXML = "<SIGNONMSGSRQV1>
                <SONRQ>
                    <DTCLIENT>%s</DTCLIENT>
                    <USERID>%s</USERID>
                    <USERPASS>%s</USERPASS>
                    <LANGUAGE>ENG</LANGUAGE>
                    <APPID>%s</APPID>
                    <APPVER>%s</APPVER>
                </SONRQ>
            </SIGNONMSGSRQV1>";
        //Turns out you don't need a specific time, can do with just date
        $result = sprintf($baseXML, date("Ymd"), $this->user, $this->password, $this->app, $this->appVersion);
        return $result;
    }

    private function accountsRequest(){
        //And yay, a magic number
        return generateMessage("<ACCTINFORQ>
                                <DTACCTUP>19700101000000</DTACCTUP>
                            </ACCTINFORQ>");
    }

    private function generateMessage($prevMessage){
        $baseXML = "<SIGNUPMSGSRQV1>
                        <ACCTINFOTRNRQ>
                            <TRNUID>%s</TRNUID>
                            <CLTCOOKIE>%s</CLTCOOKIE>" . $prevMessage ."
                        </ACCTINFOTRNRQ>
                    </SIGNUPMSGSRQV1>";
        //For some reason, we have to generate a new UUID every time, and give it a fresh cookie. Strongly considering nicknaming the server after a sesame street character.
        $result = sprintf($baseXML, $this->uuid(32), $this->nextCookie());
        return $result;
    }

    /*
    Throws a GuzzleHttp\Exception\RequestException if receive a 400-level code, or other connection error
    GuzzleHttp\Exception\ServerException for 500 level code response
    */
    public function getAccounts(){
        $client = new GuzzleHttp\Client();
        $request = new Request('POST', $this->url);
        $body = $this->getHeaders() . "<OFX>" . $this->signOnMessage() .$this->accountsRequest() . "</OFX>";
        $response = $client->send($request, [
            'body' => $body,
            'headers' => [
                "User-Agent" => "banker-php",
                "Accept" => 'application/x-ofx',
                "Content-Type" => 'application/x-ofx'
            ]
        ]);
        //If no exception has been thrown by now, we're good to go!'
        //This will be an ASCII string
        if(!$response->isSuccessful()){
            return [];
        }
        $responseBody = (string) $response->getBody();
        
    }

    private function getHeaders(){
        return "OFXHEADER:200\r\n" .
        "DATA:OFXSGML\r\n" .
        "VERSION:". $this->ofxVersion . '\r\n' .
        "SECURITY:NONE\r\n" .
        "ENCODING:USASCII\r\n" .
        "CHARSET:1252\r\n" .
        "COMPRESSION:NONE\r\n" .
        "OLDFILEUID:NONE\r\n" .
        "NEWFILEUID:" . $this->uuid(32) . "\r\n" .
         '\r\n';
    }

    public function getStatement(){

    }
}