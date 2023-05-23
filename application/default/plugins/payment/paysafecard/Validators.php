<?php

class Validators {
	
    public static function validateStringNotNull($argument, $argumentName) {
        if (empty($argument)) {
            throw new InvalidArgumentException($argumentName . ' is a required parameter.');
        }
    }

    public static function validateCurrency($currency) {
        if ($currency == null || strlen($currency) != 3) {
            throw new InvalidArgumentException('currency is a required parameter. It must contain a 3 letter ISO 4217 currency code.');
        }
    }
    
    public static function validateCards($cards) {
        if ($cards == null || count($cards) < 1) {
            throw new InvalidArgumentException("At least one card is required.");
        }
        foreach ($cards as $card) {
            if ($card == null) {
                throw new InvalidArgumentException(
                     "Cards is a required parameter and should not contain null values.");
            }
            if (empty($card['pin'])) {
	        throw new InvalidArgumentException('pin is a required parameter.');
            }
        }
    }
}

