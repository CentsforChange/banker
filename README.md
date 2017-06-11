# Banker

[![Codacy Badge](https://api.codacy.com/project/badge/Grade/f3825865ab02497496e9dbf52854dfa4)](https://www.codacy.com/app/erikdevelopments/banker?utm_source=github.com&utm_medium=referral&utm_content=CentsforChange/banker&utm_campaign=badger)

Banker is a PHP library designed to make OFX requests for bank account information, and then returned that information in the form of PHP objects. 

It supports retrieval of bank account information as well as statement retrieval. Supports all account types except investment, may encounter issues with Discover Card.

Used internally at [Cents for Change](https://centsforchange.net/).

## Installation
Using [Composer](https://getcomposer.org/):


```bash
$ composer require centsforchange/banker
```

## Usage
```php
$fid = "5959"; //Example for Bank of America-- all can be found at www.ofxhome.com
$org = "HAN"; //Same as above
$url = "https://eftx.bankofamerica.com/eftxweb/access.ofx"; //See above

$user = "example"; //The log in username for the user who's data you are trying to fetch
$password = "password"; //Their password plain text-- please don't store this-- this library doesn't and you shouldn't either. 

$clientId = ""; //Optional. Only required if using OFX version 103-- $clientId defaults to empty string
$appVersion = "2900"; //Optional. The default app version is 2500, don't change this unless you have a reason-- but there are reasons for doing so.
$ofxVersion = "102"; //Optional. This library only supports 102 and 103, defaults to 102
$app = "QWIN"; //Optional. Only change this if you have a reason-- not tested for anything other than QWIN

//Constructor
$banker = new \CentsforChange\Banker($fid, $org, $url, $user, $password, $clientId, $appVersion, $ofxVersion, $app);

//Returns an array of accounts
$account = $banker->getAccounts()[0];

$account->accountNumber; // Full account number
$account->routingNumber; // Full routing number
$account->accountType; // one of CHECKING, SAVINGS, CREDITCARD, potentially INVESTMENT (not supported for further requests)

//Get a checking or savings account statement
$statement = $banker->getStatement();

//Get a credit card statement
$statement = $banker->getStatement();

//The statement object is the same
$statement->currency; //USD
$statement->startDate; //\DateTimeInterface whenever the first day of this statement is
$statement->endDate; //\DateTimeInterface last day, usually today

$transactions = $statement->transactions; //Array of all transactions from this period.
$tranaction = $transactions[0];
$transaction->amount; //8.640000 (float)
$transaction->date; // Date the transaction posted
//Full list of transaction details  at https://github.com/asgrim/ofxparser/blob/master/lib/OfxParser/Entities/Transaction.php
```
## License
Banker is released by Cents for Change under the Apache 2.0 license.

## Dependencies
Banker depends on only three packages [GuzzleHTTP]("http://docs.guzzlephp.org/en/latest/"), [OFXParser]("https://github.com/asgrim/ofxparser"), and [Carbon]("http://carbon.nesbot.com/"), all of which are released under the MIT License.