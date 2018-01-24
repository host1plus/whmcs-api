<?php

namespace Controllers;

// whmcs namespaces
use \WHMCS\Database\Capsule;

// enums
use \Host1Plus\Enums\{Errors};

// exceptions
use \REST\Exceptions\{BadRequest, NotFound, NotImplemented, Unauthorized};
use \Host1Plus\Exception\{BadRequest as H1PBadRequest, NotFound as H1PNotFound};
use \Illuminate\Database\QueryException;

abstract class aController
{
    /**
     *
     * @param type $client
     * @param string $method
     * @param array $params
     * @return mixed
     * @throws BadRequest
     * @throws NotFound
     * @todo improve that if H!PNotFound is caught throw more generic message rather than full response from remote server API?
     */
    final protected static function executeApiRequest($client, string $method, array $params = [])
    {
        try
        {
            return \call_user_func_array([$client, $method], $params);
        }
        catch (\InvalidArgumentException $ex)
        {
            throw new BadRequest($ex->getMessage(), 0, $ex);
        }
        catch (H1PBadRequest $ex)
        {
            throw new BadRequest($ex->getMessage(), 0, $ex);
        }
        catch (H1PNotFound $ex)
        {
            throw new NotFound($ex->getMessage(), 0, $ex);
        }
    }

    final protected static function getHosting(int $id, int $userId, string $serverType, bool $isAdmin = false, array $states = [])
    {
        try
        {
            // retrieve hosting service
            if ($isAdmin)
                $hosting = Capsule::table('tblhosting')->where('id', $id)->first();
            else
                $hosting = Capsule::table('tblhosting')->where([['id', $id], ['userid', $userId]])->first();

            if (\is_null($hosting))
                throw new NotFound( \sprintf(Errors::NotFound, 'hosting service', 'id', $id) );

            // confirm that package of the hosting belongs to proper requesting module identified by $serverType
            $productCount = Capsule::table('tblproducts')->where([['id', $hosting->packageid], ['servertype', $serverType]])->count();
            if ($productCount !== 1)
                throw new NotFound( \sprintf(Errors::NotFound, 'hosting service', 'id', $id) );

            // confirm that $hosting is in proper state
            if (\count($states) > 0 && !\in_array($hosting->domainstatus, $states))
                throw new BadRequest( \sprintf(Errors::InvalidState, 'hosting service', \join(',', $states), $hosting->domainstatus) );

            return $hosting;
        }
        catch (QueryException $ex)
        {
            throw new NotFound( \sprintf(Errors::NotFound, 'hosting service', 'id', $id), 0, $ex );
        }
    }

    final protected static function getHostingApiUrlKeyRemoteId(int $id, int $userId, string $serverType, bool $isAdmin = false, array $states = [])
    {
        try
        {
            // confirm that module is properly configured
            $addonSettings = Capsule::table('tbladdonmodules')->where('module', 'h1papi')->whereIn('setting', ['option1', 'option2'])->pluck('value', 'setting');
            if (\count($addonSettings) != 2)
                throw new NotImplemented( \sprintf(Errors::NotImplemented, 'Host1Plus API Module', 'please contact system administrator') );

            $apiUrl = $addonSettings['option1'];
            $apiKey = $addonSettings['option2'];

            // retrieve hosting service
            $hosting = self::getHosting($id, $userId, $serverType, $isAdmin, $states);

            // retrieve $remoteId that is used to communicate with correct service
            $remoteId = Capsule::table('tblcustomfieldsvalues')
                    ->join('tblcustomfields', 'tblcustomfieldsvalues.fieldid', '=', 'tblcustomfields.id')
                    ->select('tblcustomfieldsvalues.value')
                    ->where([['tblcustomfields.fieldname', 'id'], ['tblcustomfieldsvalues.relid', $id]])
                    ->first();
            if (\is_null($remoteId))
                throw new NotFound( \sprintf(Errors::NotFound, 'hosting service', 'id', $id) );

            return [$hosting, $apiUrl, $apiKey, (int)$remoteId->value];
        }
        catch (QueryException $ex)
        {
            throw new NotFound( \sprintf(Errors::NotFound, 'hosting service', 'id', $id), 0, $ex );
        }
    }

    final protected static function selectPaymentGateway(string $paymentGateway, string $hostingGateway, int $userId)
    {
        if ($paymentGateway != '')
        {
            $isVisible = Capsule::table('tblpaymentgateways')->where([['gateway', $paymentGateway], ['setting', 'visible'], ['value', 'on']])->count();
            if ($isVisible !== 1)
                throw new BadRequest( \sprintf(Errors::NotFoundSimple, 'paymentGateway') );

            return $paymentGateway;
        }
        else
        {
            $defaultGateway = Capsule::table('tblclients')->where('id', $userId)->pluck('defaultgateway');
            if (\count($defaultGateway) != 1)
                throw new Unauthorized;

            return ($defaultGateway[0] == '') ? $hostingGateway : $defaultGateway[0];
        }
    }

    final protected static function getHostingConfigOptions(int $id)
    {
        $configOpts = Capsule::table('tblhostingconfigoptions')
                    ->select('tblproductconfigoptions.id as id', 'tblproductconfigoptions.optionname AS name', 'tblproductconfigoptions.qtyminimum AS min', 'tblproductconfigoptions.qtymaximum AS max', 'tblhostingconfigoptions.qty')
                    ->join('tblproductconfigoptions', 'tblproductconfigoptions.id', '=', 'tblhostingconfigoptions.optionid')
                    ->where('tblhostingconfigoptions.relid', $id)
                    ->get();

        $c = \count($configOpts);
        for ($i = 0; $i < $c; $i++)
        {
            $configOpts[$configOpts[$i]->name] = $configOpts[$i];
            unset($configOpts[$i]);
        }

        return $configOpts;
    }

    final protected static function getProductConfigOptions(int $id, string $serverType)
    {
        return Capsule::table('tblproductconfigoptions')
                    ->select('tblproductconfigoptions.optionname as name', 'tblproductconfigoptions.qtyminimum as min', 'tblproductconfigoptions.qtymaximum as max', 'tblproductconfigoptionssub.optionname as unit')
                    ->join('tblproductconfigoptionssub', 'tblproductconfigoptionssub.configid', '=', 'tblproductconfigoptions.id')
                    ->join('tblproductconfiglinks', 'tblproductconfiglinks.gid', '=', 'tblproductconfigoptions.gid')
                    ->join('tblproducts', 'tblproducts.id', '=', 'tblproductconfiglinks.pid')
                    ->where([['tblproducts.id', $id], ['tblproducts.servertype', $serverType]])
                    ->get();
    }

    final protected static function parseConfigOption(array &$params, $param, string $paramName, array $configOpts, string $confKey, $qtyMult = 1)
    {
        if (!isset($configOpts[$confKey]))
            throw new NotImplemented;

        $opt = $configOpts[$confKey];

        if (!\is_int($param))
            throw new BadRequest( \sprintf(Errors::InvalidParameter, $paramName, 'integer', $param) );

        if ($qtyMult > 1 && ($param % $qtyMult) != 0)
            throw new BadRequest( \sprintf(Errors::InvalidParameter, $paramName, "integer divisible by {$qtyMult}", $param) );

        $val = $param / $qtyMult;
        if ($opt->qty > $val || $opt->min > $val || $opt->max < $val)
        {
            if ($qtyMult != 1)
            {
                $qty = $opt->qty * $qtyMult;
                $min = $opt->min * $qtyMult;
                $max = $opt->max * $qtyMult;
            }
            else
            {
                $qty = $opt->qty;
                $min = $opt->min;
                $max = $opt->max;
            }

            throw new BadRequest( \sprintf(Errors::InvalidParameter, $paramName, "greater than: {$qty}, min: {$min}, max: {$max}", $param) );
        }

        if ($val > $opt->qty)
            $params[$opt->id] = $val;
    }

    final protected static function calcClientTax(int $clientId, $price)
    {
        $currency = $price->getCurrency();
        $taxRates = Capsule::table('tbltax')->select('tbltax.taxrate as rate')
                ->join('tblclients', 'tblclients.country', '=', 'tbltax.country')
                ->where('tblclients.id', $clientId)->get();

        if (\count($taxRates) != 1)
            return \sprintf("%s%s%s", $currency['prefix'], '0,00', $currency['suffix']);

        $tax = \round(((float)$price->toNumeric() * (float)$taxRates[0]->rate) / 100, 2);

        return \sprintf("%s%s%s", $currency['prefix'], \number_format($tax, 2, ',', ''), $currency['suffix']);
    }
}