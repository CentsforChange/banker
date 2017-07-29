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
use OfxParser\Ofx;
use Carbon\Carbon;

class Banker
{
    private $fid;

    private $org;

    private $url;

    private $user;

    private $password;

    private $clientId;

    private $appVersion;

    private $ofxVersion;

    private $app;

    //Starts at 3, don't ask me why but it works
    private $cookie = 3;

    private function nextCookie()
    {
        $this->cookie = $this->cookie + 1;
        return (string) $this->cookie;
    }
    
    public function __constructor($fid, $org, $url, $user, $password, $clientId = "", $appVersion = "2700", $ofxVersion = "102", $app = "QWIN")
    {
        $this->fid = $fid;
        $this->org = $org;
        $this->url = $url;
        $this->user = $user;
        $this->password = $password;
        $this->clientId = $clientId;
        $this->appVersion = $appVersion;
        $this->ofxVersion = $ofxVersion;
        $this->app = $app;
    }
    
    /**
     * Generates a Base 36 random string.
     * OFX requests frequently need random strings, this provides, probably overzealous in the number of bytes it uses, but cryptographically secure.
     *
     * @param  integer $length the length of the generated string
     * @return string a random string of length $length
     **/
    private function uuid($length)
    {
        //Generate a bunch of bytes to be sure we've got enough, substring it
        return substr(base_convert(random_bytes($length * 8), 8, 36), -1 * $length);
    }
    /**
     * Generates the log on credentials for requests.
     * This must be the first message after the OFX tag in any file that requires an authorized request.
     *
     * @return string the OFX with user credentials rendered
     **/
    private function signOnMessage()
    {
        if ($this->ofxVersion != 103) {
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
            $result = sprintf($baseXML, date("YmdHMS"), $this->user, $this->password, $this->app, $this->appVersion);
            return $result;
        } else {
            $baseXML = "<SIGNONMSGSRQV1>
                <SONRQ>
                    <DTCLIENT>%s</DTCLIENT>
                    <USERID>%s</USERID>
                    <USERPASS>%s</USERPASS>
                    <LANGUAGE>ENG</LANGUAGE>
                    <APPID>%s</APPID>
                    <APPVER>%s</APPVER>
                    <CLIENTUID>%s</CLIENTUID>
                </SONRQ>
            </SIGNONMSGSRQV1>";
            $result = sprintf($baseXML, date("YmdHMS"), $this->user, $this->password, $this->app, $this->appVersion, $this->clientId);
            return $result;
        }
    }

    /**
     * Generates a request for the account information.
     * Generates the basic request to list all accounts for a given authenticated user.
     *
     * @return string the message
     **/
    private function accountsRequest()
    {
        //And yay, a magic number
        return $this->generateMessage(
            "SIGNUP", "ACCTINFO", "<ACCTINFORQ>
                                <DTACCTUP>19700101000000</DTACCTUP>
                            </ACCTINFORQ>"
        );
    }
    /**
     * The baseline OFX for a message to be sent.
     * Handles the bureaucratic mark up OFX requires
     *
     * @param  string $topTag      the top level part of the OFX-- it's whatever shares the tag with MSGSRQV1
     * @param  string $lowTag      the tag before content-- in the same tag (precedes) TRNRQ
     * @param  string $prevMessage the request to wrap a message around
     * @return string the newly wrapped message-- just slap on authentication, header and <OFX> and you're ready to send
     **/
    private function generateMessage($topTag, $lowTag, $prevMessage)
    {
        $baseXML = "<".$topTag."MSGSRQV1>
                        <".$lowTag."TRNRQ>
                            <TRNUID>%s</TRNUID>
                            <CLTCOOKIE>%s</CLTCOOKIE>" . $prevMessage ."
                        </".$lowTag."TRNRQ>
                    </".$topTag."MSGSRQV1>";
        //For some reason, we have to generate a new UUID every time, and give it a fresh cookie. Strongly considering nicknaming the server after a sesame street character.
        $result = sprintf($baseXML, $this->uuid(32), $this->nextCookie());
        return $result;
    }

    /**
     * Makes a request to the OFX server, whatever the request may be
     *
     * @return Guzzle\Http\Message\Response the response to the OFX request
     */
    private function makeRequest($query)
    {
        $client = new GuzzleHttp\Client();
        $request = new Request('POST', $this->url);
        $body = $this->getHeaders() . "<OFX>" . $this->signOnMessage() . $query . "</OFX>";
        $response = $client->send(
            $request, [
            'body' => $body,
            'headers' => [
                "User-Agent" => "banker-php",
                "Accept" => 'application/x-ofx',
                "Content-Type" => 'application/x-ofx'
            ]
            ]
        );
        return $response;
    }

    /*
    Throws a GuzzleHttp\Exception\RequestException if receive a 400-level code, or other connection error
    GuzzleHttp\Exception\ServerException for 500 level code response
    */
    public function getAccounts()
    {
        $response = $this->makeRequest($this->accountsRequest());
        //If no exception has been thrown by now, we're good to go!
        //This will be an ASCII string
        $responseBody = (string) $response->getBody();
        $ofxParser = new \OfxParser\Parser();
        $ofx = $ofxParser->loadFromString($responseBody);
        //Returns an array of BankAccount objects
        return $ofx->bankAccounts();
    }

    private function accountStatementRequest($routing, $accountNumber, $accountType, $days)
    {
        $since = Carbon::now()->subDays($days)->format('Ymd');
        $baseXML = "<STMTRQ>
                    <BANKACCTFROM>
                        <BANKID>%s</BANKID>
                        <ACCTID>%s</ACCTID>
                        <ACCTTYPE>%s</ACCTTYPE>
                    </BANKACCTFROM>
                    <INCTRAN>
                        <DTSTART>%s</DTSTART>
                        <INCLUDE>Y</DTSTART>
                    </INCTRAN>
            </STMTRQ>
        ";
        $res = sprintf($baseXML, $routing, $accountNumber, $accountType, $since);
        return $this->generateMessage("BANK", "STMT", $res);
    }

    private function getAccountStatement($routing, $accountNumber, $accountType, $days)
    {
        $request = $this->accountStatementRequest($routing, $accountNumber, $accountType, $days);
        $response = $this->makeRequest($request);
        //Exceptions may have been thrown by this point, if not we have a valid set of OFX
        $responseBody = (string) $response->getBody();
        $ofxParser = new \OfxParser\Parser();
        $ofx = $ofxParser->loadFromString($responseBody);
        //We're only fetching one account
        return $ofx->bankAccounts[0]->statement;
    }

    private function creditCardStatementRequest($number, $days)
    {
        $since = Carbon::now()->subDays($days)->format('Ymd');
        $baseXML = "<CCSTMTRQ>
                    <CCACCTFROM>
                        <ACCTID>%s</ACCTID>
                    </CCACCTFROM>
                    <INCTRAN>
                        <DTSTART>%s</DTSTART>
                        <INCLUDE>Y</DTSTART>
                    </INCTRAN>
            </CCSTMTRQ>
        ";
        $res = sprintf($baseXML, $number, $since);
        return $this->generateMessage("CREDITCARD", "CCSTMT", $res);
    }

    private function getCreditCardStatement($number, $days)
    {
        $request = $this->creditCardStatementRequest($number, $days);
        $response = $this->makeRequest($request);
        $responseBody = (string) $response->getBody();
        $ofxParser = new \OfxParser\Parser();
        $ofx = $ofxParser->loadFromString($responseBody);
        return $ofx->bankAccounts[0]->statement;
    }

    /**
     * @return string The headers for an OFX request, include these before <OFX>, but not in the HTTP headers
     **/
    private function getHeaders()
    {
        //We'll generate a UUID for this request and bring the headers we're going to need for each request
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

    /**
     * Get the account statement.
     * Fetches the account statement for any bank or credit card and returns. No support for investment accounts
     *
     * @return OfxParser\Entities\Statement statement has $currency, $startDate, $endDate, and an array of $transactions
     */
    public function getStatement($accountType, $accountNumber, $routing = "", $days = 60)
    {
        if ($accountType === "CREDITCARD") {
            return $this->getCreditCardStatement($accountNumber, $days);
        } else {
            return $this->getAccountStatement($routing, $accountNumber, $accountType, $days);
        }
    }
}
