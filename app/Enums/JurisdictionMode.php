<?php

namespace App\Enums;

enum JurisdictionMode: string
{
    case Global = 'GLOBAL';
    case CountrySpecific = 'COUNTRY_SPECIFIC';
    case MultiCountry = 'MULTI_COUNTRY';
}
