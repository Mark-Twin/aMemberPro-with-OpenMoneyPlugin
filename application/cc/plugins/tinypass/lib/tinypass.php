<?php

class TPPolicy {

	const DISCOUNT_TOTAL_IN_PERIOD = "d1";
	const DISCOUNT_PREVIOUS_PURCHASE = "d2";
	const STRICT_METER_BY_TIME = "sm1";
	const REMINDER_METER_BY_TIME = "rm1";
	const REMINDER_METER_BY_COUNT = "rm2";
	const RESTRICT_MAX_PURCHASES = "r1";

	const POLICY_TYPE = "type";

	private $map = array();

	public function set($key, $value) {
		$this->map[$key] = $value;
		return $this;
	}

	public function toMap() {
		return $this->map;
	}
}

class TPPricingPolicy {

	public static function createBasic(array $priceOptions = null) {
		if($priceOptions == null)
				$priceOptions = array();
		return new TPBasicPricing($priceOptions);
	}
}

class TPBasicPricing extends TPPolicy {
	private $pos = array();

	public function __construct(array $pos) {
		$this->pos = array_merge($this->pos, $pos);
	}

	public function getPriceOptions() {
		return $this->pos;
	}

	public function addPriceOption($po) {
		$this->pos[] = $po;
		return $this;
	}

	public function hasActiveOptions() {
		if ($this->pos != null) {
			foreach ($this->pos as $po ) {
				if ($po->isActive(time()))
					return true;
			}
		}
		return false;
	}
}

class TPDiscountPolicy extends TPPolicy {


	public static function onTotalSpendInPeriod($amount, $withInPeriod, $discount) {
		$d = new TPDiscountPolicy();
		$d->set(TPPolicy::POLICY_TYPE, TPPolicy::DISCOUNT_TOTAL_IN_PERIOD);
		$d->set("amount", $amount);
		$d->set("withinPeriod", $withInPeriod);
		$d->set("discount", $discount);
		return $d;
	}

	public static function previousPurchased(array $rids, $discount) {
		$d = new TPDiscountPolicy();
		$d->set(TPPolicy::POLICY_TYPE, TPPolicy::DISCOUNT_PREVIOUS_PURCHASE);
		$d->set("rids", $rids);
		$d->set("discount", $discount);
		return $d;

	}
}

class TPRestrictionPolicy extends TPPolicy {

	public static function limitPurchasesInPeriodByAmount($maxAmount, $withInPeriod, $linkWithDetails = null) {

		$r = new TPRestrictionPolicy();
		$r->set(TPPolicy::POLICY_TYPE, TPPolicy::RESTRICT_MAX_PURCHASES);
		$r->set("amount", $maxAmount);
		$r->set("withinPeriod", $withInPeriod);
		if($linkWithDetails)
			$r->set("linkWithDetails", $linkWithDetails);

		return $r;
	}


}

class TPRID {

	private $id;

	function __construct($id = null) {
		$this->id = $id;
	}

	public function getID() {
		return $this->id;
	}

	public function __toString() {
		return $this->id;
	}

	public function toString() {
		return $this->id;
	}

	public static function parse($s) {
		if(is_numeric($s)) {
			return new TPRID("" . $s);
		} else if(is_string($s)) {
			return new TPRID($s);
		} else if(is_a($s, 'TPRID')) {
			return $s;
		} else {
			return "";
		}
	}

}

class TinyPassGateway {

	private $config;

	public function __construct($config = null) {
		if(!$config)
			$config = TinyPass::config();

		$this->config = $config;
	}

	public static function cancelSubscription($params) {
		$gw = new TinyPassGateway();
		try {
			return $gw->call("POST", TPConfig::$REST_CONTEXT . "/subscription/cancel", $params);
		} catch(Exception $e) {
			throw $e;
		}
	}

	public static function fetchSubscriptionDetails($params) {
		$gw = new TinyPassGateway();
		try {
			return $gw->call("GET", TPConfig::$REST_CONTEXT . "/subscription/search", $params);
		} catch(Exception $e) {
			throw $e;
		}
	}

	public static function grantAccess($params) {
		$gw = new TinyPassGateway();
		try {
			return $gw->call("POST", TPConfig::$REST_CONTEXT . "/access/grant", $params);
		} catch(Exception $e) {
			throw $e;
		}
	}

	public static function revokeAccess($params) {
		$gw = new TinyPassGateway();
		try {
			return $gw->call("POST", TPConfig::$REST_CONTEXT . "/access/revoke", $params);
		} catch(Exception $e) {
			throw $e;
		}
	}

	public static function fetchAccessDetail($params) {
		$gw = new TinyPassGateway();
		try {
			return $gw->call("GET", TPConfig::$REST_CONTEXT . "/access", $params);
		} catch(Exception $e) {
			if($e->getCode() == 404)
				return null;
			throw $e;
		}
	}

	public static function fetchAccessDetails($params, $page = 0, $pagesize = 500) {
		$gw = new TinyPassGateway();
		if(is_array($params)) {
			$params['page'] = $page;
			$params['pagesize'] = $pagesize;
		}
		return $gw->call("GET", TPConfig::$REST_CONTEXT . "/access/search", $params);
	}

	public static function fetchDownload($params) {
		$gw = new TinyPassGateway();
		try {
			return $gw->call("GET", TPConfig::$REST_CONTEXT . "/download", $params);
		} catch (Exception $e) {
			if ($e->getCode() == 404)
				return null;
			throw $e;
		}
	}

	public static function fetchUser($uid) {
		$gw = new TinyPassGateway();
		try {
			return $gw->call("GET", TPConfig::$REST_CONTEXT . "/user/" . $uid, array());
		} catch (Exception $e) {
			if ($e->getCode() == 404)
				return null;
			throw $e;
		}
	}

	public static function generateDownloadURL($params) {
		$gw = new TinyPassGateway();
		try {
			return $gw->call("GET", TPConfig::$REST_CONTEXT . "/download/url", $params);
		} catch (Exception $e) {
			if ($e->getCode() == 404)
				return null;
			throw $e;
		}
	}
	public function call($method, $action, $query) {


		$signature = $this->buildSignature($method, $action, $query);
		$header = array("authorization: " . $signature);

		$url = $this->config->getEndPoint() . $this->buildURL($method, $action, $query);

		$ch = curl_init();

		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		if(strtolower($method) == "get") {
			curl_setopt($ch, CURLOPT_URL, $url);
		} else {
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_POST, 0);
			curl_setopt($ch, CURLOPT_POSTFIELDS, "");
		}

		$response = curl_exec($ch);
		$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		$json = json_decode($response, 1);

		if($httpcode != 200) {
			if(isset($json["error"])) {
				$message = $json["error"]['message'];
				throw new Exception("API error($httpcode):" . $message, $httpcode);
			} else {
				throw new Exception("API error($httpcode):");
			}
		} else {
			return $json;
		}
	}

	public function buildURL($method, $action, $query) {
		if(is_array($query))
			$query = http_build_query($query);

		return $action . (($query != null && strlen($query) > 0) ? "?" . $query : "");
	}

	public function buildSignature($method, $action, $query) {
		$aq = $this->buildURL($method, $action, $query);
		$signStr = ($this->config->AID . ":" . TPSecurityUtils::hashHmacSha($this->config->PRIVATE_KEY, $method . " " . $aq));
		return $signStr;
	}

}

class TPResource {

	protected $rid;
	protected $rname;
	protected $url;

	function __construct($rid = null, $rname = null, $url = null) {
		$this->rid = $rid;
		$this->rname = $rname;
		$this->url = $url;
	}

	public function getRID() {
		return $this->rid;
	}

	public function setRID($rid) {
		$this->rid = $rid;
		return $this;
	}

	public function getRIDHash() {
		return new TPRIDHash($this->rid);
	}

	public function getName() {
		return $this->rname;
	}

	public function setName($rname) {
		$this->rname = $rname;
		return $this;
	}
	public function getURl() {
		return $this->url;
	}

	public function setURL($url) {
		$this->url = $url;
		return $this;
	}


}

class TPOffer {

	private $resource;
	private $pricing;
	private $policies = array();
	private $tags = array();

	public function __construct(TPResource $resource, $priceOptions) {
		$this->resource = $resource;

		if($priceOptions instanceof TPBasicPricing) {
			$this->pricing = $priceOptions;
		} else {
			if(!is_array($priceOptions))
				$priceOptions = array($priceOptions);
			$this->pricing = TPPricingPolicy::createBasic($priceOptions);
		}

	}

	public function getResource() {
		return $this->resource;
	}

	public function getPricing() {
		return $this->pricing;
	}

	public function addPolicy($policy) {
		$this->policies[] = $policy;
		return $this;
	}

	public function getPolicies() {
		return $this->policies;
	}

	public function addPriceOption(TPPriceOption $priceOption) {
		$this->pricing->addPriceOption($priceOption);
	}

	public function addPriceOptions() {
		foreach(func_get_args() as $arg) {
			if($arg instanceof TPPriceOption)
				$this->pricing->addPriceOption($arg);
		}
	}

	public function hasActivePrices() {
		return $this->getPricing()->hasActiveOptions();
	}

	public function addTags($tags) {
		if(!is_array($tags)) {
			$tags = func_get_args();
		}
		$this->tags = array_merge($this->tags, $tags);
		return $this;
	}

	public function getTags() {
		return $this->tags;
	}



}

class TinyPassException extends Exception {

	public $code = 0;

	public function __construct($message, $code = 0) {
		parent::__construct($message);
		$this->code = 0;
	}


}

class TPTokenUnparseable extends Exception {

	public function __construct($message) {
		parent::__construct($message);
	}

}

class TPPriceOption {

	protected $price;
	protected $accessPeriod;
	protected $startDateInSecs;
	protected $endDateInSecs;
	protected $caption;
	protected $trialPeriod;
	protected $recurring = false;

	protected $splitPay = array();


	public function __construct($price = null, $acessPeriod = null, $startDateInSecs = null, $endDateInSecs = null) {
		$this->setPrice($price);
		$this->accessPeriod = $acessPeriod;
		if($startDateInSecs)
			$this->startDateInSecs = TPTokenData::convertToEpochSeconds($startDateInSecs);
		if($endDateInSecs)
			$this->endDateInSecs = TPTokenData::convertToEpochSeconds($endDateInSecs);
	}

	public function getPrice() {
		return $this->price;
	}

	public function setPrice($price) {
		$this->price = $price;
		return $this;
	}

	public function getAccessPeriod() {
		return $this->accessPeriod;
	}

	public function getAccessPeriodInMsecs() {
		return TPUtils::parseLoosePeriodInMsecs($this->accessPeriod);
	}

	public function getAccessPeriodInSecs() {
		return $this->getAccessPeriodInMsecs() / 1000;
	}

	public function setAccessPeriod($expires) {
		$this->accessPeriod = $expires;
		return $this;
	}

	public function getStartDateInSecs() {
		return $this->startDateInSecs;
	}

	public function setStartDateInSecs($startDateInSecs) {
		$this->startDateInSecs = $startDateInSecs;
		return $this;
	}

	public function getEndDateInSecs() {
		return $this->endDateInSecs;
	}

	public function setEndDateInSecs($endDate) {
		$this->endDateInSecs = $endDate;
		return $this;
	}

	public function addSplitPay($email, $amount) {
		if(preg_match('/%$/', $amount)) {
			$amount = (double)substr($amount, 0, strlen($amount)-1);
			$amount = $amount / 100.0;
		}
		$this->splitPay[$email] = $amount;
		return $this;
	}

	public function getSplitPays() {
		return $this->splitPay;
	}

	public function getCaption() {
		return $this->caption;
	}

	public function setCaption($caption) {
		if($caption!=null && strlen($caption) > 50)
			$caption = substr($caption, 0, 50);
		$this->caption = $caption;
		return $this;
	}

	public function isActive($timestampSecs) {
		$timestampSecs = TPTokenData::convertToEpochSeconds($timestampSecs);
		if ($this->getStartDateInSecs() != null && $this->getStartDateInSecs() > $timestampSecs) return false;
		if ($this->getEndDateInSecs() != null && $this->getEndDateInSecs() < $timestampSecs) return false;
		return true;
	}

	public function __toString() {
		$sb = "";
		$sb.("Price:").($this->getPrice());
		$sb.("\tPeriod:").($this->getAccessPeriod());
		$sb.("\tTrial Period:").($this->getAccessPeriod());

		if ($this->getStartDateInSecs() != null) {
			$sb.("\tStart:").($this->getStartDateInSecs()).(":").( date('D, d M Y H:i:s' , $this->getStartDateInSecs()));
		}

		if ($this->getEndDateInSecs() != null) {
			$sb.("\tEnd:").($this->getEndDateInSecs()).(":").(date('D, d M Y H:i:s', $this->getEndDateInSecs()));
		}

		if ($this->getCaption() != null) {
			$sb.("\tCaption:").($this->getCaption());
		}

		if ($this->splitPay != null) {
			foreach ($this->splitPay as $key => $value)
				$sb.("\tSplit:").($key).(":").($value);
		}

		return $sb;
	}

}

class TPUtils {
	const SPLIT_ADDR = "/\./";

	const EXPIRE_PARSER = '/(\d+)\s*(\w+)/';

	public static function isIpValid($ipAddress) {
		return $ipAddress && preg_match("/\d{1,3}\.\d{1,3}\.\d{1,3}.\d{1,3}/", $ipAddress);
	}

	public static function shortenIP($idAddress) {
		if("0:0:0:0:0:0:0:1" == $idAddress) $idAddress = "127.0.0.1";

		try {
			$elems = preg_split(TPUtils::SPLIT_ADDR, $idAddress);
			$value = $elems[0] | ($elems[1] << 8) | ($elems[2] << 16) | ($elems[3] << 24);
			return dechex($value);
		} catch(Exception $ex) {

		}

		return null;
	}

	public static function unshortenIP($address) {
		if ($address == null) return "";

		$res = "";
		$res.=(hexdec($address) & 0xff);
		$res.=(".");
		$res.=((hexdec($address) >> 8) & 0xff);
		$res.=(".");
		$res.=((hexdec($address) >> 16) & 0xff);
		$res.=(".");
		$res.=((hexdec($address) >> 24) & 0xff);

		return $res;
	}



	public static function parseLoosePeriodInSecs($period) {
		return self::parseLoosePeriodInMsecs($period)	/1000;
	}

	public static function parseLoosePeriodInMsecs($period) {
		if (preg_match("/^-?\d+$/", $period)) {
			return (int)$period;
		}
		$matches = array();
		if (preg_match(self::EXPIRE_PARSER, $period, $matches)) {
			$num = $matches[1];
			$str = $matches[2];
			switch ($str[0]) {
				case 's':
					return $num * 1000;
				case 'm':

					if (strlen($str) > 1 && $str[1] == 'i')
						return $num * 60 * 1000;
					else if (strlen($str) > 1 && $str[1] == 's')
						return $num;
					else if (strlen($str) == 1 || $str[1] == 'o')
						return $num * 30 * 24 * 60 * 60 * 1000;

				case 'h':
					return $num * 60 * 60 * 1000;
				case 'd':
					return $num * 24 * 60 * 60 * 1000;
				case 'w':
					return $num * 7 * 24 * 60 * 60 * 1000;
				case 'y':
					return $num * 365 * 24 * 60 * 60 * 1000;
			}
		}

		throw new TinyPassException("Cannot parse the specified period: " . $period);
	}

	public static function now(){
		return time();
	}

}

class TPPurchaseRequest {


	private $primaryOffer;
	private $secondaryOffer;
	private $options;
	private $userRef;
	private $ipAddress;
	private $callback;
	private $config;

	function __construct(TPOffer $offer, array $options = null) {
		$this->config = TinyPass::config();
		$this->primaryOffer = $offer;

		if($options == null)
			$options = array();

		$this->options = $options;

	}

	public function getPrimaryOffer() {
		return $this->primaryOffer;
	}

	public function getSecondaryOffer() {
		return $this->secondaryOffer;
	}

	public function setSecondaryOffer(TPOffer $offer = null) {
		$this->secondaryOffer = $offer;
		return $this;
	}

	public function getOptions() {
		return $this->options;
	}

	public function setOptions($options) {
		$this->options = $options;
	}

	public function setUserRef($userRef) {
		if (!$userRef) return $this;
		$userRef = trim($userRef);
		if (strlen($userRef) == 0) return $this;
		$this->userRef = $userRef;
		return $this;
	}

	public function getUserRef() {
		return $this->userRef;
	}


	public function enableIPTracking($userIPAddress) {
		if ($userIPAddress == null) return $this;
		$userIPAddress = trim($userIPAddress);
		if (strlen($userIPAddress) == 0) return $this;
		$this->ipAddress = $userIPAddress;
		return $this;
	}


	public function getClientIP() {
		return $this->ipAddress;
	}

	public function generateTag() {
		$widget = new TPHtmlWidget($this->config);
		return $widget->createButtonHTML($this);
	}

	public function setCallback($s) {
		$this->callback = $s;
		return $this;
	}

	public function getCallback() {
		return $this->callback;
	}


	public function generateLink($returnURL, $cancelURL = null) {
		if ($returnURL != null) $this->options["return_url"] = $returnURL;
		if ($cancelURL != null) $this->options["cancel_url"] = $cancelURL;

		$builder = new TPClientBuilder();
		$ticketString = $builder->buildPurchaseRequest($this);

		return $this->config->getEndPoint() . TPConfig::$CONTEXT . "/jsapi/auth.js?aid=" . $this->config->AID . "&r=" . $ticketString;
	}

}

class TPHtmlWidget {


	private $config;

	public function __construct($config = null) {
		if(!$config)
			$config = TinyPass::config();

		$this->config = $config;

	}

	public function createButtonHTML($request) {

		$options = $request->getOptions();
		$rid = $request->getPrimaryOffer()->getResource()->getRID();
		$builder = new TPClientBuilder();
		$rdata = $builder->buildPurchaseRequest($request);

		$sb = "";

		$sb.=("<tp:request type=\"purchase\" ");

		$sb.=("rid=\"").($rid).("\"");

		$sb.=(" url=\"").($this->config->getEndPoint()).(TPConfig::$CONTEXT).("\"");
		$sb.=(" rdata=\"").(preg_replace('/"/', '\"', $rdata)).("\"");
		$sb.=(" aid=\"").($this->config->AID).("\"");
		$sb.=(" cn=\"").(TPConfig::getTokenCookieName($this->config->AID)).("\"");
		$sb.=(" v=\"").(TPConfig::$VERSION).("\"");

		if ($request->getCallback())
			$sb.=(" oncheckaccess=\"").($request->getCallback()).("\"");

		if ($options != null) {

			if (isset($options["button.html"])) {
				$sb.=(" custom=\"").(preg_replace('/"/', '&quot;', $options["button.html"])).("\"");
			} elseif (isset($options["button.link"])) {
				$sb.=(" link=\"").(preg_replace('/"/', '&quot;', $options["button.link"])).("\"");
			}

		}

		$sb.=(">");
		$sb.=("</tp:request>");

		return $sb;
	}

}

class TPClientMsgBuilder {

	private $builder;
	private $privateKey;

	function __construct($privateKey) {
		$this->privateKey = $privateKey;
		$this->builder = new TPClientBuilder($privateKey);
	}
	public function parseLocalTokenList($aid, array $cookies) {
		return $this->parseToken($aid, $cookies, TinyPass::$LOCAL_COOKIE_SUFFIX);
	}

	public function parseAccessTokenList($aid, array $cookies) {
		return $this->parseToken($aid, $cookies, TinyPass::$COOKIE_SUFFIX);
	}

	public function buildPurchaseRequest($tickets) {
		return $this->builder->buildPurchaseRequest($tickets);
	}

	public function buildAccessTokenList($tokentList) {
		return $this->builder->buildAccessTokenList($tokentList);
	}

	public function parseToken($aid, array $cookies, $cookieName) {
		if (($cookies == null) || (count($cookies) == 0)) return new TPAccessTokenList();
		$cookieName = TinyPass::getAppPrefix($aid) . $cookieName;
		$token = null;

		foreach ($cookies as $name => $value) {
			if ($name == $cookieName) {
				$token = $value;
				break;
			}
		}

		if($token == null)
			return new TPAccessTokenList($aid, null);

		$token = urldecode($token);

		if (($token != null) && (count($token) > 0)) {
			$parser = new TPClientParser($this->privateKey);
			$accessTokenList = $parser->parseAccessTokenList($token);
			$accessTokenList->setRawToken($token);
			return $accessTokenList;
		}

		return new TPAccessTokenList($aid, null);
	}




}

class TPClientBuilder {

	const TYPE_JSON = 'j';

	const ENCODING_AES = 'a';
	const ENCODING_OPEN = 'o';

	const STD_ENC = "{jax}";
	const ZIP_ENC = "{jzx}";
	const OPEN_ENC = "{jox}";

	private $builder;
	private $encoder;

	private $privateKey;

	private $mask = "";

	function __construct($settings = null) {
		$this->privateKey = TinyPass::$PRIVATE_KEY;
		$this->mask.=("{");
		switch ($settings!=null && strlen($settings)>1 ? $settings{1} : self::TYPE_JSON) {
			case self::TYPE_JSON:
			default:
				$this->builder = new TPJsonMsgBuilder();
				$this->mask.=(self::TYPE_JSON);
		}
		switch ($settings!=null && strlen($settings)>2 ? $settings{2} : self::ENCODING_AES) {
			case self::ENCODING_OPEN:
				$this->encoder = new TPOpenEncoder();
				$this->mask.=(self::ENCODING_OPEN);
				break;
			case self::ENCODING_AES:
			default:
				$this->encoder = new TPSecureEncoder($this->privateKey);
				$this->mask.=(self::ENCODING_AES);
		}
		$this->mask.=("x");
		$this->mask.=("}");
	}

	/**
	 *
	 * @param <type> $tokens - can be a TPAccessTokne or TPAccessTokenList
	 * @return <type>
	 */
	public function buildAccessTokens($tokens) {

		if($tokens instanceof TPAccessToken) {
//			$tokens = array($tokens);
			$tokens = new TPAccessTokenList(array($tokens));
		}

		return $this->mask . $this->encoder->encode($this->builder->buildAccessTokens($tokens));
	}

	public function buildPurchaseRequest($requests) {

		if($requests instanceof TPPurchaseRequest) {
			$requests = array($requests);
		}

		return $this->mask . $this->encoder->encode($this->builder->buildPurchaseRequest($requests));
	}

}

class TPClientParser {

	private $parser;
	private $encoder;

	const DATA_BLOCK_START_SIGNATURE = '/[{]\w\w\w[}]/';
	private $config;

	function __construct() {
		$this->config = TinyPass::config();
	}

	public function parseAccessTokens($message) {
		$tokens = array();
		$blocks = $this->splitMessageString($message);
		foreach($blocks as $block) {
			$s = $this->setupImpls($block);
			$list = $this->parser->parseAccessTokens($this->encoder->decode($s));
			$tokens  = array_merge($tokens, $list->getTokens());
		}
		return new TPAccessTokenList($tokens);
	}

	function splitMessageString($message) {
		$list = array();
		$start = -1;

		$matches  = array();
		preg_match_all(TPClientParser::DATA_BLOCK_START_SIGNATURE, $message, $matches, PREG_OFFSET_CAPTURE);

		if(count($matches) == 0)
			return $list;

		$matches = $matches[0];


		foreach($matches as $match) {
			if($start >= 0) {
				$list[] = trim(substr($message, $start, ($match[1]-$start)));
			}
			$start = $match[1];
		}

		if($start >= 0) {
			$list[] = trim(substr($message, $start));
		}

		return $list;
	}


	private function setupImpls($str) {

		if(!$str)
			$str = TPClientBuilder::STD_ENC;

		switch (strlen($str)>1 ? $str{1} : TPClientBuilder::TYPE_JSON) {

			case TPClientBuilder::TYPE_JSON:
			default:
				$this->parser = new TPJsonMsgBuilder();

		}
		switch (strlen($str)>2 ? $str{2} : TPClientBuilder::ENCODING_AES) {

			case TPClientBuilder::ENCODING_OPEN:
				$this->encoder = new TPOpenEncoder();
				break;

			case TPClientBuilder::ENCODING_AES:
			default:
				$this->encoder = new TPSecureEncoder($this->config->PRIVATE_KEY);
		}
		return preg_replace('/^{...}/','', $str);
	}



}

class TPCookieParser {


	const COOKIE_PARSER = "/([^=\s]+=[^=;]*)/";

	public static function extractCookieValue($cookieName, $rawCookieString) {

		if (!$rawCookieString) {
			return;
		}

		$splitWorked = false;

		$matches = array();

		preg_match_all(self::COOKIE_PARSER, $rawCookieString, $matches);

		if(count($matches[1]) == 0)
			return $rawCookieString;

		$matches = $matches[1];

		foreach($matches as $match) {

			$splitWorked = true;
			$match = preg_split("/=/", $match);
			$key = $match[0];
			$val = $match[1];
			if ($cookieName == $key) {
				return $val;
			}
		}

		if ($splitWorked)
			return null;

		return $rawCookieString;

	}
}

class TPSecureEncoder {

	private $privateKey;

	function __construct($privateKey) {
		$this->privateKey = $privateKey;
	}

	public function encode($msg) {
		return TPSecurityUtils::encrypt($this->privateKey, $msg);
	}

	public function decode($msg) {
		return TPSecurityUtils::decrypt($this->privateKey, $msg);
	}
}

class TPOpenEncoder {

	public function encode($msg) {
		return $msg;
	}

	public function decode($msg) {
		return $msg;
	}

}

class TPJsonMsgBuilder {

	public function parseAccessTokens($raw) {

		if($raw == null || $raw == "")
			return null;

		$json = (array) json_decode($raw);
		$accessTokenList = array();

		if(!is_array($json)) {
			$tokenMap = $json;
			$ridHash = TPRIDHash::parse($tokenMap[TPTokenData::RID]);
			$token = $this->parseAccessToken($ridHash, $tokenMap);
			$accessToken = new TPAccessToken($token);
			$accessTokenList[] = $accessToken;
		} else {
			try {
				//1.0 tokens cannot be parsed in this version
				if(isset($json['tokens']))
					return new TPAccessTokenList($accessTokenList);

				foreach($json as $tokenMap) {
					$tokenMap = (array) $tokenMap;

					if(isset($tokenMap['rid']) == false)
						continue;

					if(array_key_exists('rid', $tokenMap) && $tokenMap['rid'] == '')
						continue;

					$rid = TPRID::parse($tokenMap[TPTokenData::RID]);
					$token = $this->parseAccessToken($rid->toString(), $tokenMap);
					$accessToken = new TPAccessToken($token);
					$accessTokenList[] = $accessToken;
				}
			} catch(Exception $e) {
				return new TPAccessTokenList($accessTokenList);
			}
		}

		return new TPAccessTokenList($accessTokenList);
	}

	private function parseAccessToken($rid, $map) {

		$token = new TPTokenData($rid);

		$fields = array(
				TPTokenData::ACCESS_ID,
				TPTokenData::EX,
				TPTokenData::IPS,
				TPTokenData::UID,
				TPTokenData::EARLY_EX,
				TPTokenData::CREATED_TIME,
				TPTokenData::METER_TRIAL_ENDTIME,
				TPTokenData::METER_LOCKOUT_PERIOD,
				TPTokenData::METER_LOCKOUT_ENDTIME,
				TPTokenData::METER_TRIAL_ACCESS_ATTEMPTS,
				TPTokenData::METER_TRIAL_MAX_ACCESS_ATTEMPTS,
				TPTokenData::METER_TYPE,
		);


		foreach($fields as $f) {
			if(isset($map[$f]))
				$token->addField($f, $map[$f]);
		}


		return $token;
	}

	public function buildPurchaseRequest(array $requestData) {
		$list = array();

		foreach($requestData as $request) {
			$list[] = $this->buildRequest($request);
		}

		return json_encode($list);
	}

	public function buildRequest(TPPurchaseRequest $request) {

		$ticketMap = array();

		$ticketMap["o1"] = $this->buildOffer($request->getPrimaryOffer());
		$ticketMap["t"] = TPUtils::now();
		$ticketMap["v"] = TPConfig::$MSG_VERSION;
		$ticketMap["cb"] = $request->getCallback();

		if($request->getClientIP())
			$ticketMap["ip"] = $request->getClientIP();

		if($request->getUserRef() != null)
			$ticketMap["uref"] = $request->getUserRef();

		if($request->getOptions() != null && count($request->getOptions()) > 0)
			$ticketMap["opts"] = $request->getOptions();

		if($request->getSecondaryOffer() != null) {
			$ticketMap["o2"] = $this->buildOffer($request->getSecondaryOffer());
		}


		return $ticketMap;
	}

	private function buildOffer(TPOffer $offer, $options = array()) {

		$map = array();
		$map["rid"] = $offer->getResource()->getRID();
		$map["rnm"] = $offer->getResource()->getName();
		$map["rurl"] = $offer->getResource()->getURL();

		if($offer->getTags())
			$map["tags"] = $offer->getTags();

		$pos = array();
		$priceOptions = $offer->getPricing()->getPriceOptions();
		for($i = 0; $i < count($priceOptions); $i++)
			$pos["opt" . ($i)] = $this->buildPriceOption($priceOptions[$i], $i);
		$map["pos"] = $pos;

		$pol = array();
		$policies = $offer->getPolicies();
		foreach($policies as $policy) {
			$pol[] = $policy->toMap();
		}
		$map["pol"] = $pol;

		return $map;
	}

	public function buildAccessTokens(TPAccessTokenList $list) {
		$tokens = array();

		foreach($list->getTokens() as $token) {
			$tokens[] = $token->getTokenData()->getValues();
		}
		return json_encode($tokens);
	}

	public function buildAccessToken($accessToken) {
		return json_encode($accessToken->getTokenData()->getValues());
	}

	private function nuller($value) {
		return $value != null ? $value : "";
	}

	private function buildPriceOption(TPPriceOption $po, $index) {
		$map = array();
		$map["price"] = $this->nuller($po->getPrice());
		$map["exp"] = $this->nuller($po->getAccessPeriod());

		if($po->getStartDateInSecs() != null && $po->getStartDateInSecs() != 0)
			$map["sd"] = $this->nuller($po->getStartDateInSecs());

		if($po->getEndDateInSecs() != null && $po->getEndDateInSecs() != 0)
			$map["ed"] = $this->nuller($po->getEndDateInSecs());

		$map["cpt"] = $this->nuller($po->getCaption());

		if(count($po->getSplitPays()) > 0) {
			$splits = array();
			foreach($po->getSplitPays() as $email => $amount) {
				array_push($splits, "$email=$amount");
			}
			$map["splits"] = $splits;
		}
		return $map;
	}

}

class TPSecurityUtils {

	const DELIM = '~~~';

	public static function hashCode($s) {
		if($s == null || strlen($s) == 0) return "0";

		$hash = "0";
		$h = "0";
		if (bccomp($h,"0")==0) {
			$off = "0";
			$val =  TPSecurityUtils::unistr_to_ords($s);
			$len = count($val);
			for ($i = 0; $i < $len; $i++) {
				$temp = $h;
				for($j=0; $j<30; $j++) {
					$temp = TPSecurityUtils::addNums($temp, $h);
//				echo "---------> :" . $temp. "\n";
				}
				$h = TPSecurityUtils::addNums($temp, $val[$off++]);
//			echo "MAIN:" . $h. "\n";
			}
			$hash = $h;
		}
		return $hash;
	}

	static function unistr_to_ords($str, $encoding = 'UTF-8') {
// Turns a string of unicode characters into an array of ordinal values,
// Even if some of those characters are multibyte.
		$str = mb_convert_encoding($str,"UCS-4BE",$encoding);
		$ords = array();

// Visit each unicode character
		for($i = 0;
		$i < mb_strlen($str,"UCS-4BE");
		$i++) {
// Now we have 4 bytes. Find their total
// numeric value.
			$s2 = mb_substr($str,$i,1,"UCS-4BE");
			$val = unpack("N",$s2);
			$ords[] = $val[1];
		}
		return($ords);
	}

	public static function encrypt($keyString,  $value) {
		$origKey = $keyString;

		if(strlen($keyString) > 32)
			$keyString = substr($keyString, 0, 32);
		if (strlen($keyString) < 32)
			$keyString = str_pad($keyString, 32, 'X');

		$cipher = mcrypt_module_open(MCRYPT_RIJNDAEL_128, '', MCRYPT_MODE_ECB, '');

		$iv = TPSecurityUtils::genRandomString(16);

		if (mcrypt_generic_init($cipher, $keyString, $iv) != -1) {
			$blockSize = mcrypt_get_block_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_ECB);
			$padding   = $blockSize - (strlen($value) % $blockSize);
			$value .= str_repeat(chr($padding), $padding);
			// PHP pads with NULL bytes if $value is not a multiple of the block size..
			$cipherText = mcrypt_generic($cipher,$value);
			mcrypt_generic_deinit($cipher);
			mcrypt_module_close($cipher);
			$safe = TPSecurityUtils::urlensafe($cipherText);
			return  $safe . TPSecurityUtils::DELIM . TPSecurityUtils::hashHmacSha256($origKey, $safe);
		}

		$safe = TPSecurityUtils::urlensafe($value);
		return  $safe . TPSecurityUtils::DELIM . TPSecurityUtils::hashHmacSha256($origKey, $safe);

	}

	public static function urlensafe($data) {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	public static function urldesafe($data) {
		return base64_decode(strtr($data, '-_', '+/'));
	}

	public static function decrypt($keyString, $data) {

		$pos = strrpos($data, TPSecurityUtils::DELIM);
		if($pos > 0 ){
			$data = substr($data, 0, $pos);
		}

		$data = (TPSecurityUtils::urldesafe($data));
		if(strlen($keyString) > 32)
			$keyString = substr($keyString, 0, 32);
		if (strlen($keyString) < 32)
			$keyString = str_pad($keyString, 32, 'X');

		$iv = TPSecurityUtils::genRandomString(16);

		$cipher = mcrypt_module_open(MCRYPT_RIJNDAEL_128, '', MCRYPT_MODE_ECB, '');

		if (mcrypt_generic_init($cipher, $keyString, $iv) != -1) {

			$cipherText =  mdecrypt_generic ($cipher , $data);

			mcrypt_generic_deinit($cipher);
			mcrypt_module_close($cipher);

			$endCharVal = ord(substr( $cipherText, strlen( $cipherText)-1, 1 ));
			if ( $endCharVal <= 16 && $endCharVal >= 0 ) {
				$cipherText = substr($cipherText, 0, 0-$endCharVal); //Remove the padding (ascii value == ammount of padding)
			}

			return $cipherText;
		}


	}

	static function genRandomString($length = 10) {
		$characters = '0123456789abcdefghijklmnopqrstuvwxyz';
		$string = '';

		for ($p = 0; $p < $length; $p++) {
			$string .= $characters[mt_rand(0, strlen($characters)-1)];
		}

		return $string;
	}

	public static function hashHmacSha1($key, $value) {
		return self::urlensafe(hash_hmac('sha1', $value, $key, true));
	}

	public static function hashHmacSha256($key, $value) {
		return self::urlensafe(hash_hmac('sha256', $value, $key, true));
	}

	public static function hashHmacSha($key, $value) {
		if(in_array('sha256', hash_algos()))
			return self::hashHmacSha256($key, $value);
		else if(in_array('sha1', hash_algos()))
			return self::hashHmacSha1($key, $value);
		else
			throw new Exception("Could not load hashing algorithm sha1/sha256");

	}

}

class TPAccessTokenStore {

	protected $config;
	protected $tokens;
	protected $rawCookie;

	public function __construct($config = null) {
		$this->config = TinyPass::config();
		if($config)
			$this->config = $config;
		$this->tokens = new TPAccessTokenList();
	}

	public function getAccessToken($rid) {

		$token = $this->tokens->getAccessTokenByRID($rid);

		if($token != null)
			return $token;

		$token = new TPAccessToken(new TPRID($rid));
		$token->getTokenData()->addField(TPTokenData::EX, -1);

		if($token->getAccessState() == null) {
			if($tokens->size() == 0) {
				$token->setAccessState(TPAccessState::NO_TOKENS_FOUND);
			} else {
				$token->setAccessState(AccessState::RID_NOT_FOUND);
			}
		}

		return $token;
	}

	public function loadTokensFromCookie($cookies, $cookieName = null) {

		if($cookieName == null) {
			$cookieName = TPConfig::getAppPrefix($this->config->AID) . TPConfig::$COOKIE_SUFFIX;
		}

		$unparsedTokenValue = '';
		if(is_array($cookies)) {
			foreach($cookies as $name => $value) {
				if($name == $cookieName) {
					$unparsedTokenValue = $value;
					break;
				}
			}
		} else {
			$unparsedTokenValue = $cookies;
		}

		$this->rawCookie = $unparsedTokenValue;

		if($unparsedTokenValue != null) {
			$parser = new TPClientParser();
			$this->tokens = $parser->parseAccessTokens(urldecode($unparsedTokenValue));
		}
	}

	public function getTokens() {
		return $this->tokens;
	}

	public function hasToken($rid) {
		return $this->tokens->getAccessTokenByRID($rid);
	}

	public function getRawToken() {
		return "";
	}

	protected function _cleanExpiredTokens() {
		$tokens = $this->tokens->getTokens();

		foreach($tokens as $rid => $token) {

			if($token->isMetered() && $token->_isTrialDead()) {
				$this->tokens->remove($rid);
			} else if($token->isExpired()) {
				$this->tokens->remove($rid);
			}
		}
	}

	public function findActiveToken($regexp) {
		$tokens = $this->tokens->getTokens();

		foreach($tokens as $rid => $token) {
			if(preg_match($regexp, $rid)) {
				if($token->isExpired() == false)
					return $token;
			}
		}
		return null;
	}

	public function getUID() {
		$tokens = $this->tokens->getTokens();
		foreach ($tokens as $rid => $token) {
			return $token->getUID();
		}
		return null;
	}

}

class TPMeterStore extends TPAccessTokenStore {

	public function getMeter($rid) {
		return new TPMeter($this->getAccessToken($rid));
	}

	public function getTokens() {
		return parent::getTokens();
	}

	public function loadTokensFromCookie($cookieName = null, $rawCookieString = null) {
		parent::loadTokensFromCookie($cookieName, $rawCookieString);
		parent::_cleanExpiredTokens();
	}

	public function hasToken($rid) {
		return parent::hasToken($rid);
	}

	public function getRawCookie() {
		return parent::getRawCookie();
	}

}

class TPMeterHelper {

	public static function loadMeterFromSerialziedData($serilizedData) {
		$store = new TPAccessTokenStore();
		$store->loadTokensFromCookie($serilizedData);

		$accessToken;
		if (count($store->getTokens()) > 0) {
			$accessToken = $store->getTokens()->first();
		} else {
			return null;
		}
		$meter = new TPMeter($accessToken);

		if ($meter->_isTrialDead())
			return null;

		return $meter;
	}

	/**
	 *
	 * @param type $meterName - name of your meter you are tracking
	 * @param type $cookieValue - token string
	 * @return null|\TPMeter
	 */
	public static function loadMeterFromCookie($meterName, $cookieValue) {
		$store = new TPAccessTokenStore();
		$store->loadTokensFromCookie($cookieValue, $meterName);

		if ($store->hasToken($meterName)) {
			$accessToken = $store->getAccessToken($meterName);
			$meter = new TPMeter($accessToken);
			if ($meter->_isTrialDead())
				return null;
			return $meter;
		} else {
			return null;
		}
	}

	public static function generateCookeEmbedScript($name, $meter) {
		$sb = "";
		$sb.=("<script>");
		$sb.=("document.cookie= '") . (self::__generateLocalCookie($name, $meter)) . (";");
		$sb.=("path=/;");

		$expires = "expires=' + new Date(new Date().getTime() + 1000*60*60*24*90).toGMTString();";
		if ($meter->isLockoutPeriodActive()) {
			$expires = "expires=" . ($meter->getLockoutEndTimeSecs() + 60) * 1000 + "';";
		}
		//TODO adjust for time and count based - time should end after lockout
		$sb.=($expires);
		$sb.=("</script>");
		return $sb;
	}

	public static function createViewBased($name, $maxViews, $withinPeriod) {
		return TPMeter::createViewBased($name, $maxViews, $withinPeriod);
	}

	public static function createTimeBased($name, $trialPeriod, $lockoutPeriod) {
		return TPMeter::createTimeBased($name, $trialPeriod, $lockoutPeriod);
	}

	public static function __generateLocalToken($name, $meter) {
		$builder = new TPClientBuilder();
		return $builder->buildAccessTokens(new TPAccessToken($meter->getData()));
	}

	public static function __generateLocalCookie($name, $meter) {
		$builder = new TPClientBuilder();
		return $name . "=" . urlencode($builder->buildAccessTokens(new TPAccessToken($meter->getData())));
	}

	/**
	 * Serialize a meter into an encrypted String
	 *
	 * @param meter the meter to serialize
	 * @return serialized data
	 */
	public static function serialize($meter) {
		$builder = new TPClientBuilder();
		return $builder->buildAccessTokens(new TPAccessToken($meter->getData()));
	}

	/**
	 * Serialize a meter into a JSON String
	 *
	 * @param meter the meter to serialize
	 * @return serialized data returned as a JSON String
	 */
	public static function serializeToJSON($meter) {
		$builder = new TPClientBuilder(TPClientBuilder::OPEN_ENC);
		return $builder->buildAccessTokens(new TPAccessToken($meter->getData()));
	}

	/**
	 * Construct a meter object from serialized data (JSON or encrypted String)
	 *
	 * @param data serialized string data
	 * @return a deserialized meter
	 */
	public static function deserialize($data) {
		$parser = new TPClientParser(TPClientBuilder::OPEN_ENC);
		$list = $parser->parseAccessTokens($data);
		if ($list != null && count($list) > 0)
			return new TPMeter($list->first());
		return null;
	}

}

class TPMeter {

	private $accessToken;

	public function __construct(TPAccessToken $accessToken) {
		$this->accessToken = $accessToken;
	}

	/**
	 * Create a Meter based upon the number of views
	 * @param name name of the meter that can be stored as a cookie
	 * @param maxViews max number of views allowed before meter expires
	 * @param trialPeriod the period in which the max number of views is allowed
	 * @return a new meter
	 */
	public static function createViewBased($name, $maxViews, $trialPeriod) {
		$accessToken = new TPAccessToken(new TPRID($name));
		$accessToken->getTokenData()->addField(TPTokenData::METER_TYPE, TPTokenData::METER_REMINDER);
		$accessToken->getTokenData()->addField(TPTokenData::METER_TRIAL_MAX_ACCESS_ATTEMPTS, $maxViews);
		$accessToken->getTokenData()->addField(TPTokenData::METER_TRIAL_ACCESS_ATTEMPTS, 0);

		$trialPeriodParsed = TPUtils::parseLoosePeriodInSecs($trialPeriod);
		$trialEndTime = TPUtils::now() + $trialPeriodParsed;
		$accessToken->getTokenData()->addField(TPTokenData::METER_TRIAL_ENDTIME, $trialEndTime);
		$accessToken->getTokenData()->addField(TPTokenData::METER_LOCKOUT_ENDTIME, $trialEndTime);

		return new TPMeter($accessToken);
	}

	public static function createTimeBased($name, $trialPeriod, $lockoutPeriod) {

		$accessToken = new TPAccessToken(new TPRID($name));

		$accessToken->getTokenData()->addField(TPTokenData::METER_TYPE, TPTokenData::METER_REMINDER);

		$trialPeriodParsed = TPUtils::parseLoosePeriodInSecs($trialPeriod);
		$lockoutPeriodParsed = TPUtils::parseLoosePeriodInSecs($lockoutPeriod);
		$trialEndTime = TPUtils::now() + $trialPeriodParsed;
		$lockoutEndTime = $trialEndTime + $lockoutPeriodParsed;

		$accessToken->getTokenData()->addField(TPTokenData::METER_TRIAL_ENDTIME, $trialEndTime);
		$accessToken->getTokenData()->addField(TPTokenData::METER_LOCKOUT_ENDTIME, $lockoutEndTime);

		return new TPMeter($accessToken);

	}

	public function increment() {
		$value = $this->accessToken->getTokenData()->getFromMap(TPTokenData::METER_TRIAL_ACCESS_ATTEMPTS, 0) + 1;
		$this->accessToken->getTokenData()->addField(TPTokenData::METER_TRIAL_ACCESS_ATTEMPTS, $this->getTrialViewCount() + 1);
		return $value;
	}

	public function isTrialPeriodActive() {
		return $this->accessToken->isTrialPeriodActive();
	}

	public function isLockoutPeriodActive() {
		return $this->accessToken->isLockoutPeriodActive();
	}

	public function getData() {
		return $this->accessToken->getTokenData();
	}

	public function isMeterViewBased() {
		return $this->accessToken->isMeterViewBased();
	}


	public function getTrialViewCount() {
		return $this->accessToken->getTrialViewCount();
	}

	public function getTrialViewLimit() {
		return $this->accessToken->getTrialViewLimit();
	}


	public function _isTrialDead() {
		return $this->accessToken->_isTrialDead();
	}

	public function getMeterType() {
		return $this->accessToken->getMeterType();
	}

	public function getLockoutEndTimeSecs() {
		return $this->accessToken->getLockoutEndTimeSecs();
	}

}

class TPAccessToken {

	private $token;
	private $accessState;

	public function __construct() {

		$numargs = func_num_args();
		$this->token = new TPTokenData();

		if($numargs == 1 && func_get_arg(0) instanceof TPTokenData) {
			$this->token = func_get_arg(0);
			return;
		}

		if($numargs == 1 && func_get_arg(0) instanceof TPRID) {
			$rid = TPRID::parse(func_get_arg(0));
			$this->token->addField(TPTokenData::RID,$rid->toString());
			return;
		}

		if($numargs == 2) {
			$rid = TPRID::parse(func_get_arg(0));
			$expiration = func_get_arg(1);
			$this->token->addField(TPTokenData::RID,$rid->toString());
			$this->token->addField(TPTokenData::EX, $expiration != null ? TPTokenData::convertToEpochSeconds($expiration) : 0);
			return;
		}

		if($numargs == 3) {
			$rid = TPRID::parse(func_get_arg(0));
			$expiration = func_get_arg(1);
			$eex = func_get_arg(2);
			$this->token->addField(TPTokenData::RID,$rid->toString());
			$this->token->addField(TPTokenData::EX, $expiration != null ? TPTokenData::convertToEpochSeconds($expiration) : 0);
			$this->token->addField(TPTokenData::EARLY_EX, $eex != null ? TPTokenData::convertToEpochSeconds($eex) : 0);
			return;
		}


	}

	public function getTokenData() {
		return $this->token;
	}

	public function getAccessID() {
		return $this->token->getField(TPTokenData::ACCESS_ID);
	}

	public function getRID() {
		return new TPRID($this->token->getRID());
	}

	public function getExpirationInMillis() {
		return $this->getExpirationInSecs() * 1000;
	}

	public function getExpirationInSecs() {
		return $this->token->getFromMap(TPTokenData::EX, 0);
	}

	public function getExpirationInSeconds() {
		return $this->token->getFromMap(TPTokenData::EX, 0);
	}

	public function getEarlyExpirationInSecs() {
		return $this->token->getFromMap(TPTokenData::EARLY_EX, 0);
	}

	public function getEarlyExpirationInSeconds() {
		return $this->token->getFromMap(TPTokenData::EARLY_EX, 0);
	}

	public function getCreatedTimeInSecs() {
		return $this->token->getFromMap(TPTokenData::CREATED_TIME, 0);
	}

	public function isMetered() {
		return array_key_exists(TPTokenData::METER_TYPE, $this->token->getValues());
	}

	public function getMeterType() {
		return $this->token->getFromMap(TPTokenData::METER_TYPE, 0);
	}

	public function isTrialPeriodActive() {

		if($this->isMetered()) {

			if ($this->getMeterType() == TPTokenData::METER_STRICT) {

				$expires = $this->getTrialEndTimeSecs();
				if ($expires == null || $expires == 0)
					return false;
				return time() <= $expires;

			} else {

				if ($this->isMeterViewBased()) {
					return $this->getTrialViewCount() <= $this->getTrialViewLimit() && TPUtils::now() <= $this->getTrialEndTimeSecs();
				} else {
					$expires = $this->getTrialEndTimeSecs();
					if ($expires == null || $expires == 0)
						return true;
					return time() <= $expires;
				}

			}
		}
		return false;
	}

	public function isMeterViewBased() {
		return $this->isMetered() && array_key_exists(TPTokenData::METER_TRIAL_MAX_ACCESS_ATTEMPTS, $this->token->getValues());
	}

	public function isMeterPeriodBased() {
		return $this->isMetered() && !$this->isMeterViewBased();
	}

	public function isLockoutPeriodActive() {
		if($this->isMetered()) {
			$expires = $this->getLockoutEndTimeSecs();

			if ($this->isTrialPeriodActive())
				return false;

			if ($expires == null || $expires == 0)
				return false;

			return TPUtils::now() <= $expires;
		}
		return false;
	}

	public function _isTrialDead() {
		return $this->isLockoutPeriodActive() == false && $this->isTrialPeriodActive() == false;
	}

	public function getTrialEndTimeSecs() {
		return $this->token->getFromMap(TPTokenData::METER_TRIAL_ENDTIME, 0);
	}

	public function getLockoutEndTimeSecs() {
		return $this->token->getFromMap(TPTokenData::METER_LOCKOUT_ENDTIME, 0);
	}

	public function getTrialViewCount() {
		return $this->token->getFromMap(TPTokenData::METER_TRIAL_ACCESS_ATTEMPTS, 0);
	}

	public function getTrialViewLimit() {
		return $this->token->getFromMap(TPTokenData::METER_TRIAL_MAX_ACCESS_ATTEMPTS, 0);
	}

	public function getUID() {
		return $this->token->getFromMap(TPTokenData::UID, 0);
	}

	public function containsIP($currentIP) {
		$list = $this->token->getFromMap(TPTokenData::IPS, null);
		if($list == null) return false;
		return in_array(TPUtils::shortenIP($currentIP), $this->token->getField(TPTokenData::IPS));
	}

	public function hasIPs() {
		if($this->token->getFromMap(TPTokenData::IPS, null) == null) return false;
		return count($this->getIPs()) > 0;
	}

	public function getIPs() {
		$list = $this->token->getFromMap(TPTokenData::IPS, null);
		$res = array();
		if($list==null) return $res;
		foreach($list as $ip) {
			$res[] = TPUtils::unshortenIP($ip);
		}
		return $res;
	}


	/**
	 * Access checking functions
	 */

	public function isExpired() {
		$time = $this->getEarlyExpirationInSeconds();

		if ($time == null || $time == 0)
			$time = $this->getExpirationInSecs();

		if ($time == null || $time == 0)
			return false;

		return $time <= time();
	}
	public function isAccessGranted($clientIP = null) {
		if($this->getExpirationInSecs() == -1) {
			//special case. RID_NOT_FOUND
			if($this->accessState!=TPAccessState::NO_TOKENS_FOUND) $this->accessState = TPAccessState::RID_NOT_FOUND;
			return false;
		}

		if(TPUtils::isIpValid($clientIP) && $this->hasIPs() && !$this->containsIP($clientIP)) {
			$this->accessState = TPAccessState::CLIENT_IP_DOES_NOT_MATCH_TOKEN;
			return false;
		}

		if ($this->isMetered()) {
			if ($this->isTrialPeriodActive()) {
				$this->accessState = TPAccessState::METERED_IN_TRIAL;
				return true;
			} else if ($this->isLockoutPeriodActive()) {
				$this->accessState = TPAccessState::METERED_IN_LOCKOUT;
				return false;
			} else {
				$this->accessState = TPAccessState::METERED_TRIAL_DEAD;
				return false;
			}
		} else if ($this->isExpired()) {
			$this->accessState = TPAccessState::EXPIRED;
			return false;
		} else {
			$this->accessState = TPAccessState::ACCESS_GRANTED;
			return true;
		}
	}


//V2-CHANGE moved from TinyPass class
	public function setAccessState($accessState) {
		$this->accessState = $accessState;
	}
	public function getAccessState() {
		if($this->accessState==null) $this->isAccessGranted();
		return $this->accessState;
	}






}

class TPAccessState {

	const ACCESS_GRANTED = 100;
	const CLIENT_IP_DOES_NOT_MATCH_TOKEN = 200;
	const RID_NOT_FOUND = 201;
	const NO_TOKENS_FOUND = 202;
	const METERED_IN_TRIAL = 203;
	const EXPIRED = 204;
	const NO_ACTIVE_PRICES = 205;
	const METERED_IN_LOCKOUT = 206;
	const METERED_TRIAL_DEAD = 207;


}

class TPAccessTokenList {

	public static $MAX = 20;
	private $tokens = array();

	function __construct(array $tokens = null) {
		if ($tokens) {
			foreach ($tokens as $token) {
				$this->add($token, false);
			}
		}
	}

	public function getAccessTokens() {
		return $this->tokens;
	}

	public function getTokens() {
		return $this->tokens;
	}

	public function contains($rid) {
		$rid = TPRID::parse($rid);
		return array_key_exists($rid->toString(), $this->tokens);
	}

	public function remove($rid) {
		$rid = TPRID::parse($rid);
		unset($this->tokens[$rid->toString()]);
	}

	public function add(TPAccessToken $token, $checkLimit = true) {
		if ($checkLimit && count($this->tokens) >= TPAccessTokenList::$MAX) {
			array_pop($this->tokens);
		}
		$this->tokens[$token->getRID()->getID()] = $token;
	}

	public function addAll($tokens) {
		foreach ($tokens as $token) {
			$this->add($token);
		}
	}

	/**
	 *
	 * @param <String> $rid String RID
	 */
	public function getAccessTokenByRID($rid) {
		$rid = TPRID::parse($rid);
		if ($this->contains($rid)) {
			return $this->tokens[$rid->getID()];
		}
		return null;
	}

	public function isEmpty() {
		return $this->tokens == null || count($this->tokens) == 0;
	}

	public function size() {
		return count($this->tokens);
	}

	public function first() {
		foreach ($this->tokens as $key => $value)
			return $value;
	}

}

class TPTokenData {

	protected $map = array();

	const MARK_YEAR_MILLIS = 1293858000000;

	const METER_REMINDER = 10;
	const METER_STRICT = 20;

	const METER_TRIAL_ENDTIME = "mtet";
	const METER_TRIAL_ACCESS_PERIOD = "mtap";

	const METER_LOCKOUT_ENDTIME = "mlet";
	const METER_LOCKOUT_PERIOD = "mlp";

	const METER_TRIAL_MAX_ACCESS_ATTEMPTS = "mtma";
	const METER_TRIAL_ACCESS_ATTEMPTS = "mtaa";
	const METER_TYPE = "mt";

	//V2-CHANGE public ID field (12 char string)
	const ACCESS_ID = "id";

	const RID = "rid";
	const UID = "uid";
	const EX = "ex";
	const EARLY_EX = "eex";
	const CREATED_TIME = "ct";
	const IPS = "ips";


	public function __construct($rid = null) {
		if($rid && is_a($rid, 'TPRID')) {
			$this->map[TPTokenData::RID] = $rid->toString();
		} else if(is_string($rid)) {
			$this->map[TPTokenData::RID] = $rid;
		}
	}

	public function getRID() {
		return $this->map[TPTokenData::RID];
	}

	public function addField($s, $o) {
		$this->map[$s] = $o;
	}

	public function addFields($map) {
		$this->map = array_merge($this->map, $map);
	}

	public function getField($s) {
		return $this->map[$s];
	}

	public function getValues() {
		return $this->map;
	}

	public function size() {
		return count($this->map);
	}

	public function getFromMap($key, $defaultValue) {
		if (!array_key_exists($key, $this->map))
			return $defaultValue;
		return $this->map[$key];
	}

	public static function convertToEpochSeconds($time) {
		if ($time > TPTokenData::MARK_YEAR_MILLIS)
			return $time / 1000;
		return $time;
	}

}

class TinyPass {

	public static $API_ENDPOINT_PROD = "https://api.tinypass.com";
	public static $API_ENDPOINT_SANDBOX = "https://sandbox.tinypass.com";
	public static $API_ENDPOINT_DEV = "";

	public static $AID = "";
	public static $PRIVATE_KEY = "";
	public static $SANDBOX = false;

	public static $CONNECTION_TIMEOUT = 5000;
	public static $READ_TIMEOUT = 10000;

	public static function config($aid = null, $privateKey = null, $sandbox = null) {
		if($aid)
			return new TPConfig($aid, $privateKey, $sandbox);
		return new TPConfig(self::$AID, self::$PRIVATE_KEY, self::$SANDBOX);
	}

	public static function fetchAccessDetails($params, $page = 0, $pagesize = 500) {
		return TinyPassGateway::fetchAccessDetails($params);
	}

	public static function fetchAccessDetail($params) {
		return TinyPassGateway::fetchAccessDetail($params);
	}

	public static function grantAccess($params) {
		return TinyPassGateway::grantAccess($params);
	}

	public static function revokeAccess($params) {
		return TinyPassGateway::revokeAccess($params);
	}

	public static function cancelSubscription($params) {
		return TinyPassGateway::cancelSubscription($params);
	}

	public static function fetchSubscriptionDetails($params) {
		return TinyPassGateway::fetchSubscriptionDetails($params);
	}

	public static function fetchUserDetails($uid) {
		return TinyPassGateway::fetchUser($uid);
	}

	public static function fetchDownloadDetails($params) {
		return TinyPassGateway::fetchDownload($params);
	}

	public static function generateDownloadURL($params){
		return TinyPassGateway::generateDownloadURL($params);
	}

}

class TPConfig {

	public static $VERSION = "2.0.8";
	public static $MSG_VERSION = "2.0p";

	public static $CONTEXT = "/v2";
	public static $REST_CONTEXT = "/r2";

	public static $COOKIE_SUFFIX = "_TOKEN";
	public static $COOKIE_PREFIX = "__TP_";

	public $AID;
	public $PRIVATE_KEY;
	public $SANDBOX;

	public function __construct($aid, $privateKey, $sandbox) {
		$this->AID = $aid;
		$this->PRIVATE_KEY = $privateKey;
		$this->SANDBOX = $sandbox;
	}

	public function getEndPoint() {
		if(TinyPass::$API_ENDPOINT_DEV != null && strlen(TinyPass::$API_ENDPOINT_DEV) > 0) {
			return TinyPass::$API_ENDPOINT_DEV;
		} else if(TinyPass::$SANDBOX) {
			return TinyPass::$API_ENDPOINT_SANDBOX;
		} else {
			return TinyPass::$API_ENDPOINT_PROD;
		}
	}

	public static function getTokenCookieName($aid) {
		return self::$COOKIE_PREFIX . $aid . self::$COOKIE_SUFFIX;
	}

	public static function getAppPrefix($aid) {
		return "__TP_" . $aid;
	}

}

