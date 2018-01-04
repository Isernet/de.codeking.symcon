<?php

define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__ . '/libs/ModuleHelper.class.php');

/**
 * Class Pilight
 * IP-Symcon pilight module
 *
 * @version     0.1
 * @category    Symcon
 * @package     de.codeking.symcon
 * @author      Frank Herrmann <frank@codeking.de>
 * @link        https://codeking.de
 * @link        https://github.com/CodeKingLabs/de.codeking.symcon
 *
 */
class Pilight extends ModuleHelper
{
    protected $prefix = 'pilight';

    const guid_parent = '{041619E4-E69D-4C05-AD95-904BA3D45942}';
    const guid_send = '{F9ACF9F1-F7EA-4C39-8C28-95AA966C5672}';

    public $data = [];
    public $devices = [];

    /**
     * create instance
     */
    public function Create()
    {
        parent::Create();

        // connect parent i/o device
        $this->ConnectParent(self::guid_parent);

        // register private properties
        $this->RegisterPropertyString('Identifier', '');
    }

    /**
     * apply changes, when settings form has been saved
     */
    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // enable action on variables
        if ($ident = $this->_getIdentifierByNeedle('State')) {
            $this->force_ident = true;
            $this->EnableAction($ident);
        }
    }

    /**
     * Receive and update current data
     * @param string $JSONString
     * @return bool|void
     */
    public function ReceiveData($JSONString)
    {
        // convert json data to array
        $data = json_decode($JSONString, true);

        // get current device by identifier
        $identifier = $this->ReadPropertyString('Identifier');
        if (isset($data['Devices'][$identifier])) {
            // update variables
            foreach ($data['Devices'][$identifier] AS $key => $value) {
                if ($ident = $this->_getIdentifierByNeedle($key)) {
                    SetValue($this->GetIDForIdent($ident), $value);
                }
            }
        }
    }

    /**
     * Request Actions
     * @param string $Ident
     * @param $Value
     * @return bool|void
     */
    public function RequestAction($Ident, $Value)
    {
        // update variable
        SetValue($this->GetIDForIdent($Ident), $Value);

        // build data
        $data = [
            'DataID' => self::guid_send,
            'Device' => $this->ReadPropertyString('Identifier'),
            'Value' => $Value
        ];

        // send data to parent
        $this->SendDataToParent(json_encode($data));
    }
}