<?php

	/**
	 * Api steuert die Bezahlung per Lastschrift
	 *
	 * @copyright 2011 micropayment GmbH
	 * @link http://www.micropayment.de/
	 * @author Holger Heyne
	 * @version 1.4
	 * @created 2011-02-22 18:42:21
	 */
	interface IMcpDebitService_v1_4 {

		/**
		 * l�scht alle Kunden und Transaktionen in der Testumgebung
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode Muss 1 sein
		 * 
		 * @return void 
		 */
		public function resetTest($accessKey, $testMode);

		/**
		 * legt neuen Kunden an
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  aktiviert Testumgebung
		 * @param string $customerId (default=null)  eigene eindeutige ID des Kunden, wird anderenfalls erzeugt
		 * @param array $freeParams (default=null)  Liste mit freien Parametern, die dem Kunden zugeordnet werden
		 * 
		 * @return array 
		 * @result string $customerId eigene oder erzeugte eindeutige ID des Kunden
		 */
		public function customerCreate($accessKey, $testMode=0, $customerId=null, $freeParams=null);

		/**
		 * ordnet weitere freie Parameter dem Kunden zu, oder �ndert sie
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  aktiviert Testumgebung
		 * @param string $customerId eindeutige ID des Kunden
		 * @param array $freeParams (default=null)  Liste mit zus�tzlichen freien Parametern
		 * 
		 * @return void 
		 */
		public function customerSet($accessKey, $testMode=0, $customerId, $freeParams=null);

		/**
		 * ermittelt alle freien Parameter des Kunden
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  aktiviert Testumgebung
		 * @param string $customerId ID des Kunden
		 * 
		 * @return array 
		 * @result array $freeParams (default=null)  Liste mit allen freien Parametern
		 */
		public function customerGet($accessKey, $testMode=0, $customerId);

		/**
		 * ermittelt alle Kunden
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  aktiviert Testumgebung
		 * @param integer $from (default=0)  Position des ersten auszugebenden Kunden
		 * @param integer $count (default=100)  Anzahl der auszugebenden Kunden
		 * 
		 * @return array 
		 * @result array $customerIdList Liste mit allen freien Parametern
		 * @result integer $count Anzahl der Kunden in der Liste
		 * @result integer $maxCount Gesamtanzahl aller Kunden
		 */
		public function customerList($accessKey, $testMode=0, $from=0, $count=100);

		/**
		 * erzeugt oder �ndert Bankverbindung eines Kunden
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  aktiviert Testumgebung
		 * @param string $customerId ID des Kunden
		 * @param string $country (default='DE')  Sitz der Bank
		 * @param string $bankCode Bankleitzahl
		 * @param string $accountNumber Kontonummer
		 * @param string $accountHolder Kontoinhaber
		 * 
		 * @return array 
		 * @result string $bankName der ermittelte Name der Bank
		 * @result string $barStatus Sperr-Status der Kontoverbindung
		 */
		public function bankaccountSet($accessKey, $testMode=0, $customerId, $country='DE', $bankCode, $accountNumber, $accountHolder);

		/**
		 * ermittelt die Bankverbindung des Kunden
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  aktiviert Testumgebung
		 * @param string $customerId ID des Kunden
		 * 
		 * @return array 
		 * @result string $country Sitz der Bank
		 * @result string $bankCode Bankleitzahl
		 * @result string $bankName Name der Bank
		 * @result string $accountNumber Kontonummer
		 * @result string $accountHolder Kontoinhaber
		 * @result string $barStatus Sperr-Status der Kontoverbindung
		 */
		public function bankaccountGet($accessKey, $testMode=0, $customerId);

		/**
		 * pr�ft Bankleitzahl und ermittelt Banknamen
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  aktiviert Testumgebung
		 * @param string $country (default='DE')  Sitz der Bank
		 * @param string $bankCode Bankleitzahl
		 * 
		 * @return array 
		 * @result string $bankName Name der Bank
		 */
		public function bankCheck($accessKey, $testMode=0, $country='DE', $bankCode);

		/**
		 * pr�ft Bankverbindung und ermittelt Banknamen
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  aktiviert Testumgebung
		 * @param string $country (default='DE')  Sitz der Bank
		 * @param string $bankCode Bankleitzahl
		 * @param string $accountNumber Kontonummer
		 * 
		 * @return array 
		 * @result string $bankName der ermittelte Name der Bank
		 * @result string $barStatus Sperr-Status der Kontoverbindung
		 */
		public function bankaccountCheck($accessKey, $testMode=0, $country='DE', $bankCode, $accountNumber);

		/**
		 * Sperrt Bankverbindung oder gibt sie frei
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  aktiviert Testumgebung
		 * @param string $country (default='DE')  Sitz der Bank
		 * @param string $bankCode Bankleitzahl
		 * @param string $accountNumber Kontonummer
		 * @param string $barStatus Sperr-Status BARRED, ALLOWED
		 * 
		 * @return void 
		 */
		public function bankaccountBar($accessKey, $testMode=0, $country='DE', $bankCode, $accountNumber, $barStatus);

		/**
		 * erzeugt oder �ndert Adressdaten eines Kunden
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  aktiviert Testumgebung
		 * @param string $customerId ID des Kunden
		 * @param string $firstName Vorname
		 * @param string $surName Nachname
		 * @param string $street Strasse und Hausnummer
		 * @param string $zip Postleitzahl
		 * @param string $city Ort
		 * @param string $country (default='DE')  Land
		 * 
		 * @return void 
		 */
		public function addressSet($accessKey, $testMode=0, $customerId, $firstName, $surName, $street, $zip, $city, $country='DE');

		/**
		 * ermittelt die Adresse des Kunden
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  aktiviert Testumgebung
		 * @param string $customerId ID des Kunden
		 * 
		 * @return array 
		 * @result string $firstName Vorname
		 * @result string $surName Nachname
		 * @result string $street Strasse und Hausnummer
		 * @result string $zip Postleitzahl
		 * @result string $city Ort
		 * @result string $country Land
		 */
		public function addressGet($accessKey, $testMode=0, $customerId);

		/**
		 * erzeugt oder �ndert Kontaktdaten eines Kunden
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  aktiviert Testumgebung
		 * @param string $customerId ID des Kunden
		 * @param string $email (default=null)  Emailadresse des Kunden
		 * @param string $phone (default=null)  Festnetzanschluss
		 * @param string $mobile (default=null)  Handynummer
		 * 
		 * @return void 
		 */
		public function contactDataSet($accessKey, $testMode=0, $customerId, $email=null, $phone=null, $mobile=null);

		/**
		 * ermittelt die Kontaktdaten des Kunden
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  aktiviert Testumgebung
		 * @param string $customerId ID des Kunden
		 * 
		 * @return array 
		 * @result string $email Emailadresse
		 * @result string $phone Festnetzanschluss
		 * @result string $mobile Handynummer
		 */
		public function contactDataGet($accessKey, $testMode=0, $customerId);

		/**
		 * erzeugt einen neuen Bezahlvorgang
		 *  l�st die Benachrichtigung sessionStatus mit dem Status "INIT" bzw. "REINIT" aus
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  aktiviert Testumgebung
		 * @param string $customerId ID des Kunden
		 * @param string $sessionId (default='')  eigene eindeutige ID des Vorgangs, wird anderenfalls erzeugt
		 * @param string $project das Projektk�rzel f�r den Vorgang
		 * @param string $projectCampaign (default='')  ein Kampagnenk�rzel des Projektbetreibers
		 * @param string $account (default='')  Account des beteiligten Webmasters
		 * @param string $webmasterCampaign (default='')  ein Kampagnenk�rzel des Webmasters
		 * @param integer $amount (default=0)  abzurechnender Betrag in Cent
		 * @param string $currency (default='EUR')  W�hrung
		 * @param string $title (default='')  Bezeichnung der zu kaufenden Sache
		 * @param string $payText (default='')  Abbuchungstext der Lastschrift
		 * @param string $ip (default='')  IP des Benutzers
		 * @param array $freeParams (default=null)  Liste mit freien Parametern, die dem Vorgang zugeordnet werden
		 * 
		 * @return array 
		 * @result string $sessionId eigene oder erzeugte eindeutige ID des Vorgangs
		 * @result string $status Vorgangsstatus "INIT" oder "REINIT"
		 * @result string $expire Ablaufzeit der Best�tigung
		 */
		public function sessionCreate($accessKey, $testMode=0, $customerId, $sessionId='', $project, $projectCampaign='', $account='', $webmasterCampaign='', $amount=0, $currency='EUR', $title='', $payText='', $ip='', $freeParams=null);

		/**
		 * ordnet weitere freie Parameter der Session zu, oder �ndert sie
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  aktiviert Testumgebung
		 * @param string $sessionId ID des Vorgangs
		 * @param array $freeParams (default=null)  Liste mit zus�tzlichen freien Parametern
		 * 
		 * @return void 
		 */
		public function sessionSet($accessKey, $testMode=0, $sessionId, $freeParams=null);

		/**
		 * ermittelt Daten eines Bezahlvorgangs
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  aktiviert Testumgebung
		 * @param string $sessionId ID des Vorgangs
		 * 
		 * @return array 
		 * @result string $status Vorgangsstatus "INIT", "REINIT", "APPROVED", "CHARGED", "REVERSED" oder "RECHARGED"
		 * @result string $expire Ablaufzeit bzw. Best�tigung des Vorgangs
		 * @result string $statusDetail Beschreibung f�r gescheiterte Transaktionen
		 * @result string $customerId ID des Kunden
		 * @result string $project zugeordnetes Projekt
		 * @result string $projectCampaign zugeordnete Projektkampagne
		 * @result string $account zugeordneter Webmasteraccount
		 * @result string $webmasterCampaign zugeordnete Webmasterkampagne
		 * @result integer $amount �bergebener Betrag bzw. Standard aus Konfiguration in Cent
		 * @result integer $openAmount offener, noch zu zahlender Betrag der Session
		 * @result string $currency �bergebene W�hrung bzw. "EUR"
		 * @result string $title �bergebene Kaufsache bzw. Standard aus Konfiguration
		 * @result string $payText Abbuchungstext der Lastschrift
		 * @result string $ip �bergebene IP des Benutzers
		 * @result array $freeParams (default=null)  Liste mit allen freien Parametern
		 */
		public function sessionGet($accessKey, $testMode=0, $sessionId);

		/**
		 * best�tigt den Lastschrifteinzug eines Vorgangs
		 *  l�st die Benachrichtigung sessionStatus mit dem Status "APPROVED" aus
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  aktiviert Testumgebung
		 * @param string $sessionId ID des Vorgangs
		 * 
		 * @return array 
		 * @result string $status Vorgangsstatus "APPROVED" oder "FAILED"
		 * @result string $expire Zeitpunkt der Best�tigung
		 */
		public function sessionApprove($accessKey, $testMode=0, $sessionId);

		/**
		 * ermittelt alle Bezahlvorg�nge eines Kunden
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  aktiviert Testumgebung
		 * @param string $customerId ID des Kunden
		 * 
		 * @return array 
		 * @result integer $count Anzahl der Eintr�ge in sessionIdList
		 * @result array $sessionIdList 0-indizierte Liste mit Vorgang-IDs
		 */
		public function sessionList($accessKey, $testMode=0, $customerId);

		/**
		 * simuliert die Abbuchung f�r alle best�tigten Vorg�nge
		 *  erzeugt f�r jede best�tigte Session eine neue Transaktion mit dem Typ "BOOKING" und l�st die Benachrichtigung transactionCreate aus
		 *  l�st die Benachrichtigung sessionStatus mit dem Status "CHARGED" aus
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  muss 1 sein
		 * 
		 * @return array 
		 * @result integer $count Anzahl der gebuchten Vorg�nge
		 */
		public function sessionChargeTest($accessKey, $testMode=0);

		/**
		 * simuliert Stornierung eines einzelnen Vorgangs
		 *  erzeugt eine neue Transaktion mit dem Typ "REVERSAL" und l�st die Benachrichtigung transactionCreate aus
		 *  l�st die Benachrichtigung sessionStatus mit dem Status "REVERSED" aus
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  muss 1 sein
		 * @param string $sessionId ID des Vorgangs
		 * 
		 * @return array 
		 * @result integer $amount stornierter Betrag inkl. Geb�hr
		 */
		public function sessionReverseTest($accessKey, $testMode=0, $sessionId);

		/**
		 * simuliert die komplette Nachzahlung eines stornierten Vorgangs
		 *  erzeugt eine neue Transaktion mit dem Typ "BACKPAY" und l�st die Benachrichtigung transactionCreate aus
		 *  l�st die Benachrichtigung sessionStatus mit dem Status "RECHARGED" aus, wenn der gesamte offene Betrag beglichen wurde
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  muss 1 sein
		 * @param string $sessionId ID des Vorgangs
		 * @param integer $amount (default=null) optional der nachgezahlte Teilbetrag
		 * 
		 * @return array 
		 * @result integer $amount gebuchter Betrag
		 */
		public function sessionRechargeTest($accessKey, $testMode=0, $sessionId, $amount=null);

		/**
		 * Veranlasst eine (Teil-)Gutschrift und �berweist sie zur�ck
		 *  erzeugt eine neue Transaktion mit dem Typ "REFUND" und l�st die Benachrichtigung transactionCreate aus
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  aktiviert Testumgebung
		 * @param string $sessionId ID der zugeh�rigen Session
		 * @param string $bankCode (default=null)  Bankleitzahl des altern. Empf�ngers
		 * @param string $accountNumber (default=null)  Kontonummer des altern. Empf�ngers
		 * @param string $accountHolder (default=null)  Kontoinhaber
		 * @param integer $amount (default=null)  �berweisungsbetrag, stdm. wird der gesamte eingegangene Betrag �berwiesen
		 * @param string $payText (default=null)  Buchungstext, stdm. wird der urspr�ngliche Buchungstext verwendet
		 * 
		 * @return array 
		 * @result integer $amount �berweisungsbetrag
		 * @result string $payText Buchungstext
		 */
		public function sessionRefund($accessKey, $testMode=0, $sessionId, $bankCode=null, $accountNumber=null, $accountHolder=null, $amount=null, $payText=null);

		/**
		 * simuliert Stornierung der letzten Gutschrift,
		 *  erzeugt eine neue Transaktion mit dem Typ "REFUNDREVERSAL" und l�st die Benachrichtigung transactionCreate aus
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  muss 1 sein
		 * @param string $sessionId ID des Vorgangs
		 * 
		 * @return array 
		 * @result integer $amount stornierter Gutschriftbetrag
		 */
		public function sessionRefundReverseTest($accessKey, $testMode=0, $sessionId);

		/**
		 * erstellt eine Transaktion vom Typ "EXTERNAL"
		 *  l�st die Benachrichtigung transactionCreate aus
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  aktiviert Testumgebung
		 * @param string $sessionId ID der zugeh�rigen Session
		 * @param string $date (default=null)  Datum der Transaktion
		 * @param integer $amount Transaktionsbetrag
		 * @param string $description (default='""')  Beschreibungstext
		 * 
		 * @return void 
		 */
		public function transactionCreate($accessKey, $testMode=0, $sessionId, $date=null, $amount, $description='""');

		/**
		 * ermittelt alle Transaktionen f�r einen Bezahlvorgang
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  aktiviert Testumgebung
		 * @param string $sessionId ID des Vorgangs
		 * 
		 * @return array 
		 * @result integer $count Anzahl der Eintr�ge in transactionIdList
		 * @result array $transactionIdList 0-indizierte Liste mit Transaktions-IDs
		 */
		public function transactionList($accessKey, $testMode=0, $sessionId);

		/**
		 * ermittelt Daten einer Transaktion
		 *
		 * @param string $accessKey AccessKey aus dem Controlcenter
		 * @param integer $testMode (default=0)  aktiviert Testumgebung
		 * @param string $transactionId ID des Vorgangs
		 * 
		 * @return array 
		 * @result string $sessionId ID des Vorgangs
		 * @result string $date Datum der Transaktion
		 * @result string $type Art der Transaktion "BOOKING", "REVERSAL", "BACKPAY", "EXTERNAL", "REFUND", "REFUNDREVERSAL"
		 * @result integer $amount Transaktionsbetrag
		 * @result string $description Beschreibungstext
		 */
		public function transactionGet($accessKey, $testMode=0, $transactionId);

	}

?>