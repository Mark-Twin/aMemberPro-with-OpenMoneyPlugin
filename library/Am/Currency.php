<?php
/**
 * Manage money formatting and currencies
 * @package Am_Invoice
 */
class Am_Currency {
    static $currencyList = array(
        'AED' => array('name' => 'UAE Dirham', 'country' => array('AE'), 'numcode' => '784', 'precision' => 2),
        'AFN' => array('name' => 'Afghani', 'country' => array('AF'), 'numcode' => '971', 'precision' => 2),
        'ALL' => array('name' => 'Lek', 'country' => array('AL'), 'numcode' => '008', 'precision' => 2),
        'AMD' => array('name' => 'Armenian Dram', 'country' => array('AM'), 'numcode' => '051', 'precision' => 2),
        'ANG' => array('name' => 'Netherlands Antillean Guilder', 'country' => array('CURACAO', 'SINT MAARTEN (DUTCH PART)'), 'numcode' => '532', 'precision' => 2),
        'AOA' => array('name' => 'Kwanza', 'country' => array('AO'), 'numcode' => '973', 'precision' => 2),
        'ARS' => array('name' => 'Argentine Peso', 'country' => array('AR'), 'numcode' => '032', 'precision' => 2),
        'AUD' => array('name' => 'Australian Dollar', 'country' => array('AU', 'CX', 'CC', 'HM', 'KI', 'NR', 'NF', 'TV'), 'numcode' => '036', 'format' => '%2$s%1$s', 'symbol' => '$', 'precision' => 2),
        'AWG' => array('name' => 'Aruban Florin', 'country' => array('AW'), 'numcode' => '533', 'precision' => 2),
        'AZN' => array('name' => 'Azerbaijanian Manat', 'country' => array('AZ'), 'numcode' => '944', 'precision' => 2),
        'BAM' => array('name' => 'Convertible Mark', 'country' => array('BOSNIA & HERZEGOVINA'), 'numcode' => '977', 'precision' => 2),
        'BBD' => array('name' => 'Barbados Dollar', 'country' => array('BB'), 'numcode' => '052', 'precision' => 2),
        'BDT' => array('name' => 'Taka', 'country' => array('BD'), 'numcode' => '050', 'precision' => 2),
        'BGN' => array('name' => 'Bulgarian Lev', 'country' => array('BG'), 'numcode' => '975', 'precision' => 2),
        'BHD' => array('name' => 'Bahraini Dinar', 'country' => array('BH'), 'numcode' => '048', 'precision' => 3),
        'BIF' => array('name' => 'Burundi Franc', 'country' => array('BI'), 'numcode' => '108', 'precision' => 0),
        'BMD' => array('name' => 'Bermudian Dollar', 'country' => array('BM'), 'numcode' => '060', 'precision' => 2),
        'BND' => array('name' => 'Brunei Dollar', 'country' => array('BRUNEI DARUSSALAM'), 'numcode' => '096', 'precision' => 2),
        'BOB' => array('name' => 'Boliviano', 'country' => array('BOLIVIA, PLURINATIONAL STATE OF'), 'numcode' => '068', 'precision' => 2),
        'BOV' => array('name' => 'Mvdol', 'country' => array('BOLIVIA, PLURINATIONAL STATE OF'), 'numcode' => '984', 'precision' => 2),
        'BRL' => array('name' => 'Brazilian Real', 'country' => array('BR'), 'numcode' => '986', 'format' => '%2$s%1$s', 'symbol' => 'R$', 'precision' => 2),
        'BSD' => array('name' => 'Bahamian Dollar', 'country' => array('BS'), 'numcode' => '044', 'precision' => 2),
        'BTN' => array('name' => 'Ngultrum', 'country' => array('BT'), 'numcode' => '064', 'precision' => 2),
        'BWP' => array('name' => 'Pula', 'country' => array('BW'), 'numcode' => '072', 'precision' => 2),
        'BYR' => array('name' => 'Belarussian Ruble', 'country' => array('BY'), 'numcode' => '974', 'precision' => 0),
        'BZD' => array('name' => 'Belize Dollar', 'country' => array('BZ'), 'numcode' => '084', 'precision' => 2),
        'CAD' => array('name' => 'Canadian Dollar', 'country' => array('CA'), 'numcode' => '124', 'symbol' => '$', 'precision' => 2),
        'CDF' => array('name' => 'Congolese Franc', 'country' => array('CONGO, THE DEMOCRATIC REPUBLIC OF'), 'numcode' => '976', 'precision' => 2),
        'CHE' => array('name' => 'WIR Euro', 'country' => array('CH'), 'numcode' => '947', 'precision' => 2),
        'CHF' => array('name' => 'Swiss Franc', 'country' => array('LI', 'CH'), 'numcode' => '756', 'precision' => 2),
        'CHW' => array('name' => 'WIR Franc', 'country' => array('CH'), 'numcode' => '948', 'precision' => 2),
        'CLF' => array('name' => 'Unidades de fomento', 'country' => array('CL'), 'numcode' => '990', 'precision' => 0),
        'CLP' => array('name' => 'Chilean Peso', 'country' => array('CL'), 'numcode' => '152', 'precision' => 0),
        'CNY' => array('name' => 'Yuan Renminbi', 'country' => array('CN'), 'numcode' => '156', 'precision' => 2),
        'COP' => array('name' => 'Colombian Peso', 'country' => array('CO'), 'numcode' => '170', 'precision' => 2),
        'COU' => array('name' => 'Unidad de Valor Real', 'country' => array('CO'), 'numcode' => '970', 'precision' => 2),
        'CRC' => array('name' => 'Costa Rican Colon', 'country' => array('CR'), 'numcode' => '188', 'precision' => 2),
        'CUC' => array('name' => 'Peso Convertible', 'country' => array('CU'), 'numcode' => '931', 'precision' => 2),
        'CUP' => array('name' => 'Cuban Peso', 'country' => array('CU'), 'numcode' => '192', 'precision' => 2),
        'CVE' => array('name' => 'Cape Verde Escudo', 'country' => array('CV'), 'numcode' => '132', 'precision' => 2),
        'CZK' => array('name' => 'Czech Koruna', 'country' => array('CZ'), 'numcode' => '203', 'symbol' => 'Kč', 'precision' => 2),
        'DJF' => array('name' => 'Djibouti Franc', 'country' => array('DJ'), 'numcode' => '262', 'precision' => 0),
        'DKK' => array('name' => 'Danish Krone', 'country' => array('DK', 'FO', 'GL'), 'numcode' => '208', 'symbol' => 'kr', 'precision' => 2),
        'DOP' => array('name' => 'Dominican Peso', 'country' => array('DO'), 'numcode' => '214', 'precision' => 2),
        'DZD' => array('name' => 'Algerian Dinar', 'country' => array('DZ'), 'numcode' => '012', 'precision' => 2),
        'EGP' => array('name' => 'Egyptian Pound', 'country' => array('EG'), 'numcode' => '818', 'precision' => 2),
        'ERN' => array('name' => 'Nakfa', 'country' => array('ER'), 'numcode' => '232', 'precision' => 2),
        'ETB' => array('name' => 'Ethiopian Birr', 'country' => array('ET'), 'numcode' => '230', 'precision' => 2),
        'EUR' => array('name' => 'Euro', 'country' => array('ÅLAND ISLANDS', 'AD', 'AT', 'BE', 'CY', 'EE', 'EUROPEAN UNION ', 'FI', 'FR', 'GF', 'FRENCH SOUTHERN TERRITORIES', 'DE', 'GR', 'GP', 'HOLY SEE (VATICAN CITY STATE)', 'IE', 'IT', 'LU', 'MT', 'MQ', 'YT', 'MC', 'MONTENEGRO', 'NL', 'PT', 'RE', 'SAINT MARTIN', 'SAINT PIERRE AND MIQUELON', 'SAINT-BARTHÉLEMY', 'SM', 'SK', 'SI', 'ES', 'Vatican City State (HOLY SEE)'), 'numcode' => '978', 'format' => '%2$s%1$s', 'symbol' => '€', 'precision' => 2, 'dec_point' => '.', 'thousands_sep' => ','),
        'FJD' => array('name' => 'Fiji Dollar', 'country' => array('FIJI'), 'numcode' => '242', 'precision' => 2),
        'FKP' => array('name' => 'Falkland Islands Pound', 'country' => array('FALKLAND ISLANDS (MALVINAS)'), 'numcode' => '238', 'precision' => 2),
        'GBP' => array('name' => 'Pound Sterling', 'country' => array('GUERNSEY', 'ISLE OF MAN', 'JERSEY', 'GB'), 'numcode' => '826', 'format' => '%2$s%1$s', 'symbol' => '£', 'precision' => 2, 'dec_point' => '.', 'thousands_sep' => ','),
        'GEL' => array('name' => 'Lari', 'country' => array('GE'), 'numcode' => '981', 'precision' => 2),
        'GHS' => array('name' => 'Cedi', 'country' => array('GH'), 'numcode' => '936', 'precision' => 2),
        'GIP' => array('name' => 'Gibraltar Pound', 'country' => array('GI'), 'numcode' => '292', 'precision' => 2),
        'GMD' => array('name' => 'Dalasi', 'country' => array('GM'), 'numcode' => '270', 'precision' => 2),
        'GNF' => array('name' => 'Guinea Franc', 'country' => array('GN'), 'numcode' => '324', 'precision' => 0),
        'GTQ' => array('name' => 'Quetzal', 'country' => array('GT'), 'numcode' => '320', 'precision' => 2),
        'GYD' => array('name' => 'Guyana Dollar', 'country' => array('GY'), 'numcode' => '328', 'precision' => 2),
        'HKD' => array('name' => 'Hong Kong Dollar', 'country' => array('HONG KONG'), 'numcode' => '344', 'symbol' => '$', 'precision' => 2),
        'HNL' => array('name' => 'Lempira', 'country' => array('HN'), 'numcode' => '340', 'precision' => 2),
        'HRK' => array('name' => 'Croatian Kuna', 'country' => array('CROATIA'), 'numcode' => '191', 'precision' => 2),
        'HTG' => array('name' => 'Gourde', 'country' => array('HT'), 'numcode' => '332', 'precision' => 2),
        'HUF' => array('name' => 'Forint', 'country' => array('HU'), 'numcode' => '348', 'format' => '%s %s', 'symbol' => 'Ft.', 'precision' => 0, 'dec_point' => ',', 'thousands_sep' => '.'),
        'IDR' => array('name' => 'Rupiah', 'country' => array('ID'), 'numcode' => '360', 'format' => '%2$s%1$s', 'symbol' => 'Rp', 'precision' => 0),
        'ILS' => array('name' => 'New Israeli Sheqel', 'country' => array('IL'), 'numcode' => '376', 'precision' => 2),
        'INR' => array('name' => 'Indian Rupee', 'country' => array('BT', 'IN'), 'numcode' => '356', 'precision' => 2),
        'IQD' => array('name' => 'Iraqi Dinar', 'country' => array('IQ'), 'numcode' => '368', 'precision' => 3),
        'IRR' => array('name' => 'Iranian Rial', 'country' => array('IRAN, ISLAMIC REPUBLIC OF'), 'numcode' => '364', 'precision' => 2),
        'ISK' => array('name' => 'Iceland Krona', 'country' => array('IS'), 'numcode' => '352', 'precision' => 0),
        'JMD' => array('name' => 'Jamaican Dollar', 'country' => array('JM'), 'numcode' => '388', 'precision' => 2),
        'JOD' => array('name' => 'Jordanian Dinar', 'country' => array('JO'), 'numcode' => '400', 'precision' => 3),
        'JPY' => array('name' => 'Yen', 'country' => array('JP'), 'numcode' => '392', 'format' => '%2$s%1$s', 'symbol' => '¥', 'precision' => 0, 'dec_point' => '.', 'thousands_sep' => ','),
        'KES' => array('name' => 'Kenyan Shilling', 'country' => array('KE'), 'numcode' => '404', 'precision' => 2),
        'KGS' => array('name' => 'Som', 'country' => array('KG'), 'numcode' => '417', 'precision' => 2),
        'KHR' => array('name' => 'Riel', 'country' => array('KH'), 'numcode' => '116', 'precision' => 2),
        'KMF' => array('name' => 'Comoro Franc', 'country' => array('KM'), 'numcode' => '174', 'precision' => 0),
        'KPW' => array('name' => 'North Korean Won', 'country' => array('KOREA, DEMOCRATIC PEOPLE’S REPUBLIC OF'), 'numcode' => '408', 'precision' => 2),
        'KRW' => array('name' => 'Won', 'country' => array('KOREA, REPUBLIC OF'), 'numcode' => '410', 'precision' => 0),
        'KWD' => array('name' => 'Kuwaiti Dinar', 'country' => array('KW'), 'numcode' => '414', 'precision' => 3),
        'KYD' => array('name' => 'Cayman Islands Dollar', 'country' => array('KY'), 'numcode' => '136', 'precision' => 2),
        'KZT' => array('name' => 'Tenge', 'country' => array('KZ'), 'numcode' => '398', 'precision' => 2),
        'LAK' => array('name' => 'Kip', 'country' => array('LAO PEOPLE’S DEMOCRATIC REPUBLIC'), 'numcode' => '418', 'precision' => 2),
        'LBP' => array('name' => 'Lebanese Pound', 'country' => array('LB'), 'numcode' => '422', 'precision' => 2),
        'LKR' => array('name' => 'Sri Lanka Rupee', 'country' => array('LK'), 'numcode' => '144', 'precision' => 2),
        'LRD' => array('name' => 'Liberian Dollar', 'country' => array('LR'), 'numcode' => '430', 'precision' => 2),
        'LSL' => array('name' => 'Loti', 'country' => array('LS'), 'numcode' => '426', 'precision' => 2),
        'LTL' => array('name' => 'Lithuanian Litas', 'country' => array('LT'), 'numcode' => '440', 'precision' => 2),
        'LVL' => array('name' => 'Latvian Lats', 'country' => array('LV'), 'numcode' => '428', 'precision' => 2),
        'LYD' => array('name' => 'Libyan Dinar', 'country' => array('LIBYAN ARAB JAMAHIRIYA'), 'numcode' => '434', 'precision' => 3),
        'MAD' => array('name' => 'Moroccan Dirham', 'country' => array('MA', 'WESTERN SAHARA'), 'numcode' => '504', 'precision' => 2),
        'MDL' => array('name' => 'Moldovan Leu', 'country' => array('MOLDOVA, REPUBLIC OF'), 'numcode' => '498', 'precision' => 2),
        'MGA' => array('name' => 'Malagasy Ariary', 'country' => array('MG'), 'numcode' => '969', 'precision' => 2),
        'MKD' => array('name' => 'Denar', 'country' => array('MACEDONIA, THE FORMER YUGOSLAV REPUBLIC OF'), 'numcode' => '807', 'precision' => 2),
        'MMK' => array('name' => 'Kyat', 'country' => array('MM'), 'numcode' => '104', 'precision' => 2),
        'MNT' => array('name' => 'Tugrik', 'country' => array('MN'), 'numcode' => '496', 'precision' => 2),
        'MOP' => array('name' => 'Pataca', 'country' => array('MACAO'), 'numcode' => '446', 'precision' => 2),
        'MRO' => array('name' => 'Ouguiya', 'country' => array('MR'), 'numcode' => '478', 'precision' => 2),
        'MUR' => array('name' => 'Mauritius Rupee', 'country' => array('MU'), 'numcode' => '480', 'precision' => 2),
        'MVR' => array('name' => 'Rufiyaa', 'country' => array('MV'), 'numcode' => '462', 'precision' => 2),
        'MWK' => array('name' => 'Kwacha', 'country' => array('MW'), 'numcode' => '454', 'precision' => 2),
        'MXN' => array('name' => 'Mexican Peso', 'country' => array('MX'), 'numcode' => '484', 'symbol' => '$', 'precision' => 2),
        'MXV' => array('name' => 'Mexican Unidad de Inversion (UDI)', 'country' => array('MX'), 'numcode' => '979', 'precision' => 2),
        'MYR' => array('name' => 'Malaysian Ringgit', 'country' => array('MY'), 'numcode' => '458', 'format' => '%2$s %1$s', 'symbol' => 'RM', 'precision' => 2),
        'MZN' => array('name' => 'Metical', 'country' => array('MZ'), 'numcode' => '943', 'precision' => 2),
        'NAD' => array('name' => 'Namibia Dollar', 'country' => array('NA'), 'numcode' => '516', 'precision' => 2),
        'NGN' => array('name' => 'Naira', 'country' => array('NG'), 'numcode' => '566', 'precision' => 2),
        'NIO' => array('name' => 'Cordoba Oro', 'country' => array('NI'), 'numcode' => '558', 'precision' => 2),
        'NOK' => array('name' => 'Norwegian Krone', 'country' => array('BV', 'NO', 'SJ'), 'numcode' => '578', 'symbol' => 'kr', 'precision' => 2),
        'NPR' => array('name' => 'Nepalese Rupee', 'country' => array('NP'), 'numcode' => '524', 'precision' => 2),
        'NZD' => array('name' => 'New Zealand Dollar', 'country' => array('CK', 'NZ', 'NU', 'PITCAIRN', 'TK'), 'numcode' => '554', 'format' => '%2$s%1$s', 'symbol' => '$', 'precision' => 2),
        'OMR' => array('name' => 'Rial Omani', 'country' => array('OM'), 'numcode' => '512', 'precision' => 3),
        'PAB' => array('name' => 'Balboa', 'country' => array('PA'), 'numcode' => '590', 'precision' => 2),
        'PEN' => array('name' => 'Nuevo Sol', 'country' => array('PE'), 'numcode' => '604', 'precision' => 2),
        'PGK' => array('name' => 'Kina', 'country' => array('PG'), 'numcode' => '598', 'precision' => 2),
        'PHP' => array('name' => 'Philippine Peso', 'country' => array('PH'), 'numcode' => '608', 'format' => '%2$s%1$s', 'symbol' => '₱', 'precision' => 2),
        'PKR' => array('name' => 'Pakistan Rupee', 'country' => array('PK'), 'numcode' => '586', 'precision' => 2),
        'PLN' => array('name' => 'Zloty', 'country' => array('PL'), 'numcode' => '985', 'symbol' => 'zł', 'precision' => 2),
        'PYG' => array('name' => 'Guarani', 'country' => array('PY'), 'numcode' => '600', 'precision' => 0),
        'QAR' => array('name' => 'Qatari Rial', 'country' => array('QA'), 'numcode' => '634', 'precision' => 2),
        'RON' => array('name' => 'Leu', 'country' => array('RO'), 'numcode' => '946', 'precision' => 2),
        'RSD' => array('name' => 'Serbian Dinar', 'country' => array('SERBIA '), 'numcode' => '941', 'precision' => 2),
        'RUB' => array('name' => 'Russian Ruble', 'country' => array('RUSSIAN FEDERATION'), 'numcode' => '643', 'precision' => 2),
        'RWF' => array('name' => 'Rwanda Franc', 'country' => array('RW'), 'numcode' => '646', 'precision' => 0),
        'SAR' => array('name' => 'Saudi Riyal', 'country' => array('SA'), 'numcode' => '682', 'precision' => 2),
        'SBD' => array('name' => 'Solomon Islands Dollar', 'country' => array('SB'), 'numcode' => '090', 'precision' => 2),
        'SCR' => array('name' => 'Seychelles Rupee', 'country' => array('SC'), 'numcode' => '690', 'precision' => 2),
        'SDG' => array('name' => 'Sudanese Pound', 'country' => array('SD'), 'numcode' => '938', 'precision' => 2),
        'SEK' => array('name' => 'Swedish Krona', 'country' => array('SE'), 'numcode' => '752', 'symbol' => 'kr', 'precision' => 2),
        'SGD' => array('name' => 'Singapore Dollar', 'country' => array('SG'), 'numcode' => '702', 'symbol' => '$', 'precision' => 2),
        'SHP' => array('name' => 'Saint Helena Pound', 'country' => array('SAINT HELENA, ASCENSION AND TRISTAN DA CUNHA'), 'numcode' => '654', 'precision' => 2),
        'SLL' => array('name' => 'Leone', 'country' => array('SL'), 'numcode' => '694', 'precision' => 2),
        'SOS' => array('name' => 'Somali Shilling', 'country' => array('SO'), 'numcode' => '706', 'precision' => 2),
        'SRD' => array('name' => 'Surinam Dollar', 'country' => array('SR'), 'numcode' => '968', 'precision' => 2),
        'SSP' => array('name' => 'South Sudanese Pound', 'country' => array('SOUTH SUDAN'), 'numcode' => '728', 'precision' => 2),
        'STD' => array('name' => 'Dobra', 'country' => array('ST'), 'numcode' => '678', 'precision' => 2),
        'SVC' => array('name' => 'El Salvador Colon', 'country' => array('SV'), 'numcode' => '222', 'precision' => 2),
        'SYP' => array('name' => 'Syrian Pound', 'country' => array('SYRIAN ARAB REPUBLIC'), 'numcode' => '760', 'precision' => 2),
        'SZL' => array('name' => 'Lilangeni', 'country' => array('SZ'), 'numcode' => '748', 'precision' => 2),
        'THB' => array('name' => 'Baht', 'country' => array('TH'), 'numcode' => '764', 'symbol' => '฿', 'precision' => 2),
        'TJS' => array('name' => 'Somoni', 'country' => array('TJ'), 'numcode' => '972', 'precision' => 2),
        'TMT' => array('name' => 'New Manat', 'country' => array('TM'), 'numcode' => '934', 'precision' => 2),
        'TND' => array('name' => 'Tunisian Dinar', 'country' => array('TN'), 'numcode' => '788', 'precision' => 3),
        'TOP' => array('name' => 'Pa’anga', 'country' => array('TO'), 'numcode' => '776', 'precision' => 2),
        'TRY' => array('name' => 'Turkish Lira', 'country' => array('TR'), 'numcode' => '949', 'symbol' => 'TL', 'precision' => 2),
        'TTD' => array('name' => 'Trinidad and Tobago Dollar', 'country' => array('TT'), 'numcode' => '780', 'precision' => 2),
        'TWD' => array('name' => 'New Taiwan Dollar', 'country' => array('TAIWAN, PROVINCE OF CHINA'), 'numcode' => '901', 'symbol' => '$', 'precision' => 2),
        'TZS' => array('name' => 'Tanzanian Shilling', 'country' => array('TANZANIA, UNITED REPUBLIC OF'), 'numcode' => '834', 'precision' => 2),
        'UAH' => array('name' => 'Hryvnia', 'country' => array('UA'), 'numcode' => '980', 'precision' => 2),
        'UGX' => array('name' => 'Uganda Shilling', 'country' => array('UG'), 'numcode' => '800', 'precision' => 2),
        'USD' => array('name' => 'US Dollar', 'country' => array('AS', 'BONAIRE, SINT EUSTATIUS AND SABA', 'IO', 'EC', 'SV', 'GU', 'HT', 'MH', 'MICRONESIA, FEDERATED STATES OF', 'MP', 'PW', 'PA', 'PR', 'TIMOR-LESTE', 'TC', 'US', 'UM', 'VG', 'VIRGIN ISLANDS (US)'), 'numcode' => '840', 'format' => '%2$s%1$s', 'symbol' => '$', 'precision' => 2, 'dec_point' => '.', 'thousands_sep' => ','),
        'UYI' => array('name' => 'Uruguay Peso en Unidades Indexadas (URUIURUI)', 'country' => array('UY'), 'numcode' => '940', 'precision' => 0),
        'UYU' => array('name' => 'Peso Uruguayo', 'country' => array('UY'), 'numcode' => '858', 'precision' => 2),
        'UZS' => array('name' => 'Uzbekistan Sum', 'country' => array('UZ'), 'numcode' => '860', 'precision' => 2),
        'VEF' => array('name' => 'Bolivar Fuerte', 'country' => array('VENEZUELA, BOLIVARIAN REPUBLIC OF'), 'numcode' => '937', 'precision' => 2),
        'VND' => array('name' => 'Dong', 'country' => array('VN'), 'numcode' => '704', 'precision' => 0),
        'VUV' => array('name' => 'Vatu', 'country' => array('VU'), 'numcode' => '548', 'precision' => 0),
        'WST' => array('name' => 'Tala', 'country' => array('WS'), 'numcode' => '882', 'precision' => 2),
        'XAF' => array('name' => 'CFA Franc BEAC', 'country' => array('CM', 'CF', 'TD', 'CG', 'GA'), 'numcode' => '950', 'precision' => 0),
        'XCD' => array('name' => 'East Caribbean Dollar', 'country' => array('AI', 'AG', 'DM', 'GD', 'MS', 'SAINT KITTS AND NEVIS', 'SAINT LUCIA', 'SAINT VINCENT AND THE GRENADINES'), 'numcode' => '951', 'precision' => 2),
        'XOF' => array('name' => 'CFA Franc BCEAO', 'country' => array('BJ', 'BF', 'CI', 'GW', 'ML', 'NE', 'SN', 'TG'), 'numcode' => '952', 'precision' => 0),
        'XPF' => array('name' => 'CFP Franc', 'country' => array('PF', 'NC', 'WF'), 'numcode' => '953', 'precision' => 0),
        'YER' => array('name' => 'Yemeni Rial', 'country' => array('YE'), 'numcode' => '886', 'precision' => 2),
        'ZAR' => array('name' => 'Rand', 'country' => array('LS', 'NA', 'ZA'), 'numcode' => '710', 'precision' => 2),
        'ZMK' => array('name' => 'Zambian Kwacha', 'country' => array('ZM'), 'numcode' => '894', 'precision' => 2),
        'ZWL' => array('name' => 'Zimbabwe Dollar', 'country' => array('ZW'), 'numcode' => '932', 'precision' => 2),
//        'USN' => array('name' => 'US Dollar (Next day)', 'country' => array('US'), 'numcode' => '997', 'precision' => 2),
//        'USS' => array('name' => 'US Dollar (Same day)', 'country' => array('US'), 'numcode' => '998', 'precision' => 2),
//        'XDR' => array('name' => 'SDR (Special Drawing Right)', 'country' => array('INTERNATIONAL MONETARY FUND (IMF) '), 'numcode' => '960', 'precision' => N.A.),
//        'XSU' => array('name' => 'Sucre', 'country' => array('SISTEMA UNITARIO DE COMPENSACION REGIONAL DE PAGOS SUCRE '), 'numcode' => '994', 'precision' => null),
//        'XUA' => array('name' => 'ADB Unit of Account', 'country' => array('MEMBER COUNTRIES OF THE AFRICAN DEVELOPMENT BANK GROUP'), 'numcode' => '965', 'precision' => null),
    );
    protected $currency;
    protected $value = "NaN";

    public function __construct($currency = null, $locale = null)
    {
        if (!$currency) $currency = self::getDefault();
        if (!is_string($currency) || strlen($currency)<3)
            throw new Am_Exception_InternalError("Wrong currency code passed");
        $this->currency = $currency;
    }
    static function create($value, $currency = null, $locale = null)
    {
        $c = new self($currency, $locale);
        $c->setValue($value);
        return $c;
    }
    static function render($value, $currency = null, $locale = null)
    {
        return (string)self::create($value, $currency, $locale);
    }
    public function setValue($value)
    {
        $this->value = (float)$value;
    }
    public function getValue() 
    {
        return $this->value;
    }
    public function __toString()
    {
        $desc = & self::$currencyList[$this->currency];

        $format = isset($desc['format']) ? $desc['format'] : '%s %s';
        $symbol = isset($desc['symbol']) ? $desc['symbol'] : $this->currency;
        $precision = isset($desc['precision']) ? $desc['precision'] : 2;
        $dec_point = isset($desc['dec_point']) ? $desc['dec_point'] : '.';
        $thousands_sep = isset($desc['thousands_sep']) ? $desc['thousands_sep'] : ',';

        return sprintf($format,
            number_format((float)$this->value, $precision, $dec_point, $thousands_sep),
            $symbol);
    }
    public function toString()
    {
        return $this->__toString();
    }
    
    public function equalsTo(Am_Currency $c)
    {
        return $c->currency == $this->currency && $c->value == $this->value;
    }

    static function getSupportedCurrencies($locale=null)
    {
        $ret = array(self::getDefault());
        foreach (Am_Di::getInstance()->plugins_payment->loadEnabled()->getAllEnabled() as $pl){
            if ($list = $pl->getSupportedCurrencies())
                $ret = array_merge($ret, $list);
        }
        $ret = array_unique(array_filter($ret));
        sort($ret);
        return array_combine($ret, $ret);
    }
    /**
     * @return string default currency code
     */
    static public function getDefault()
    {
        return Am_Di::getInstance()->config->get('currency', 'USD');
    }
    
    static public function getFullList()
    {
        $list = array();
        foreach (self::$currencyList as $code => $p)
            $list[$code] = $code . ' - ' . $p['name'];
        return $list;
    }
    /**
     * Convert 3-letter ISO code to 3-digit numeric code 
     * @param type $currency
     * @return type 
     */
    static public function getNumericCode($currency)
    {
        if (empty(self::$currencyList[(string)$currency]))
            return null;
        $r = self::$currencyList[(string)$currency];
        return $r['numcode'];
    }
}