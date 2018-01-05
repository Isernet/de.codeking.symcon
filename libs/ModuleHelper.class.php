<?php

/**
 * Class ModuleHelper
 * IP-Symcon Helper Methods
 *
 * @version     0.1
 * @category    Symcon
 * @package     de.codeking.symcon
 * @author      Frank Herrmann <frank@codeking.de>
 * @link        https://codeking.de
 * @link        https://github.com/CodeKingLabs/de.codeking.symcon
 *
 */
class ModuleHelper extends IPSModule
{
    protected $prefix;

    private $archive_id;

    protected $archive_mappings = [];
    protected $profile_mappings = [];
    protected $hidden_mappings = [];

    protected $force_ident = false;

    /**
     * attach prefix to ident
     * @param string $Ident
     * @return bool
     */
    protected function EnableAction($Ident)
    {
        $Ident = $this->force_ident ? $Ident : $this->identifier($Ident);
        $this->force_ident = false;

        return parent::EnableAction($Ident);
    }

    /**
     * creates an instance by identifier
     * @param $module_id
     * @param $parent_id
     * @param $identifier
     * @param $name
     * @return mixed
     */
    protected function CreateInstanceByIdentifier($module_id, $parent_id, $identifier, $name = null)
    {
        // set name by identifier, if no name was provided
        if (!$name) {
            $name = $identifier;
        }

        // set identifier
        $identifier = $this->identifier($identifier);

        // get category id, if exists
        $instance_id = @IPS_GetObjectIDByIdent($identifier, $parent_id);

        // if category doesn't exist, create it!
        if ($instance_id === false) {
            $instance_id = IPS_CreateInstance($module_id);
            IPS_SetParent($instance_id, $parent_id);
            IPS_SetName($instance_id, $this->Translate($name));
            IPS_SetIdent($instance_id, $identifier);
        }

        // return category id
        return $instance_id;
    }

    /**
     * creates a category by identifier
     * @param $parent_id
     * @param $identifier
     * @param $name
     * @param $icon
     * @return mixed
     */
    protected function CreateCategoryByIdentifier($parent_id, $identifier, $name = null, $icon = null)
    {
        // set name by identifier, if no name was provided
        if (!$name) {
            $name = $identifier;
        }

        // set identifier
        $identifier = $this->identifier($identifier);

        // get category id, if exists
        $category_id = @IPS_GetObjectIDByIdent($identifier, $parent_id);

        // if category doesn't exist, create it!
        if ($category_id === false) {
            $category_id = IPS_CreateCategory();
            IPS_SetParent($category_id, $parent_id);
            IPS_SetName($category_id, $this->Translate($name));
            IPS_SetIdent($category_id, $identifier);

            if ($icon) {
                IPS_SetIcon($category_id, $icon);
            }
        }

        // return category id
        return $category_id;
    }

    /**
     * creates a category by identifier or updates its data
     * @param $parent_id
     * @param $name
     * @param $value
     * @param $position
     * @param $identifier
     * @return mixed
     */
    protected function CreateVariableByIdentifier($parent_id, $name, $value, $position = 0, $identifier = false)
    {
        // remove whitespaces
        $name = trim($name);
        if (is_string($value)) {
            $value = trim($value);
        }

        // set identifier
        $has_identifier = true;
        if (!$identifier) {
            $has_identifier = false;
            $identifier = $name;
        }
        $identifier = $this->identifier($identifier);

        // get archive id
        if (!$this->archive_id) {
            $this->archive_id = IPS_GetObjectIDByName('Archive', 0);
        }

        // get variable id, if exists
        $variable_created = false;
        $variable_id = @IPS_GetObjectIDByIdent($identifier, $parent_id);

        // if variable doesn't exist, create it!
        if ($variable_id === false) {
            $variable_created = true;

            // set type of variable
            $type = $this->GetVariableType($value);

            // create variable
            $variable_id = IPS_CreateVariable($type);
            IPS_SetParent($variable_id, $parent_id);
            IPS_SetIdent($variable_id, $identifier);

            // hide visibility
            if (in_array($name, $this->hidden_mappings)) {
                IPS_SetHidden($variable_id, true);
            }

            // enable archive
            if (in_array($name, $this->archive_mappings)) {
                AC_SetLoggingStatus($this->archive_id, $variable_id, true);
                IPS_ApplyChanges($this->archive_id);
            }

            // set profile
            if (isset($this->profile_mappings[$name])) {
                // attach module prefix to custom profile name
                $profile_id = substr($this->profile_mappings[$name], 0, 1) == '~'
                    ? $this->profile_mappings[$name]
                    : $this->prefix . '.' . $this->profile_mappings[$name];

                // create profile, if not exists
                if (!IPS_VariableProfileExists($profile_id)) {
                    $this->CreateCustomVariableProfile($profile_id, $this->profile_mappings[$name]);
                }

                IPS_SetVariableCustomProfile($variable_id, $profile_id);
            }
        }

        // set name & position
        if ($variable_created || $has_identifier) {
            IPS_SetName($variable_id, $name);
        }
        IPS_SetPosition($variable_id, $position);

        // set value
        SetValue($variable_id, $value);

        // return variable id
        return $variable_id;
    }

    /**
     * creates a link, if not exists
     * @param $parent_id
     * @param $target_id
     * @param $name
     * @param int $position
     * @param $icon
     * @return bool|int
     */
    protected function CreateLink($parent_id, $target_id, $name, $position = 0, $icon = null)
    {
        $link_id = false;

        // detect already created links
        $links = IPS_GetLinkList();
        foreach ($links AS $link) {
            $parent_link_id = IPS_GetParent($link);
            $link = IPS_GetLink($link);

            if ($parent_link_id == $parent_id && $link['TargetID'] == $target_id) {
                $link_id = $link['LinkID'];
                break;
            }
        }

        // if link doesn't exist, create it!
        if ($link_id === false) {
            // create link
            $link_id = IPS_CreateLink();
            IPS_SetName($link_id, $this->Translate($name));
            IPS_SetParent($link_id, $parent_id);

            // set target id
            IPS_SetLinkTargetID($link_id, $target_id);

            // hide visibility
            if (in_array($name, $this->hidden_mappings)) {
                IPS_SetHidden($link_id, true);
            }

            // set icon
            if ($icon) {
                IPS_SetIcon($link_id, $icon);
            }
        }

        // set position
        IPS_SetPosition($link_id, $position);

        return $link_id;
    }

    /**
     * replaces special chars for identifier
     * @param $identifier
     * @return mixed
     */
    public function identifier($identifier)
    {
        $identifier = strtr($identifier, [
            '-' => '_',
            ' ' => '_',
            ':' => '_',
            '(' => '',
            ')' => '',
            '%' => 'p'
        ]);

        return $this->prefix . '_' . $identifier;
    }

    /**
     * get variable type by contents
     * @param $value
     * @return int
     */
    private function GetVariableType($value)
    {
        if (is_bool($value)) {
            $type = 0;
        } else if (is_int($value)) {
            $type = 1;
        } else if (is_float($value)) {
            $type = 2;
        } else {
            $type = 3;
        }

        return $type;
    }

    /**
     * create custom variable profile
     * @param int $profile_id
     * @param string $name
     */
    private function CreateCustomVariableProfile($profile_id, $name)
    {
        switch ($name):
            case 'Price':
                IPS_CreateVariableProfile($profile_id, 2); // float
                IPS_SetVariableProfileDigits($profile_id, 2); // 2 decimals
                IPS_SetVariableProfileText($profile_id, '', ' €'); // currency symbol
                IPS_SetVariableProfileIcon($profile_id, 'Euro');
                break;
            case 'Latency':
                IPS_CreateVariableProfile($profile_id, 1); // integer
                IPS_SetVariableProfileText($profile_id, '', ' ms'); // milliseconds
                IPS_SetVariableProfileIcon($profile_id, 'Graph');
                break;
            case 'MBit.Upload':
                IPS_CreateVariableProfile($profile_id, 2); // float
                IPS_SetVariableProfileDigits($profile_id, 2); // 2 decimals
                IPS_SetVariableProfileText($profile_id, '', ' MBit'); // MBit
                IPS_SetVariableProfileIcon($profile_id, 'HollowArrowUp');
                break;
            case 'MBit.Download':
                IPS_CreateVariableProfile($profile_id, 2); // float
                IPS_SetVariableProfileDigits($profile_id, 2); // 2 decimals
                IPS_SetVariableProfileText($profile_id, '', ' MBit'); // MBit
                IPS_SetVariableProfileIcon($profile_id, 'HollowArrowDown');
                break;
            case 'Presence':
                IPS_CreateVariableProfile($profile_id, 0); // boolean
                IPS_SetVariableProfileAssociation($profile_id, 0, $this->Translate('absent'), '', -1);
                IPS_SetVariableProfileAssociation($profile_id, 1, $this->Translate('present'), '', -1);
                break;
            case 'Status':
                IPS_CreateVariableProfile($profile_id, 0); // boolean
                IPS_SetVariableProfileAssociation($profile_id, 0, '?', '', -1);
                IPS_SetVariableProfileAssociation($profile_id, 1, 'OK', '', -1);
                break;
        endswitch;
    }

    /**
     * Register a webhook
     * @param string $webhook
     * @param bool $delete
     */
    protected function RegisterWebhook($webhook, $delete = false)
    {
        $ids = IPS_GetInstanceListByModuleID("{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}");

        if (sizeof($ids) > 0) {
            $hooks = json_decode(IPS_GetProperty($ids[0], "Hooks"), true);
            $found = false;
            foreach ($hooks AS $index => $hook) {
                if ($hook['Hook'] == $webhook) {
                    if ($hook['TargetID'] == $this->InstanceID && !$delete)
                        return;
                    elseif ($delete && $hook['TargetID'] == $this->InstanceID) {
                        continue;
                    }

                    $hooks[$index]['TargetID'] = $this->InstanceID;
                    $found = true;
                }
            }
            if (!$found) {
                $hooks[] = ["Hook" => $webhook, "TargetID" => $this->InstanceID];
            }

            IPS_SetProperty($ids[0], "Hooks", json_encode($hooks));
            IPS_ApplyChanges($ids[0]);
        }
    }

    /**
     * get identifier by needle
     * @param $needle
     * @return string
     */
    protected function _getIdentifierByNeedle($needle)
    {
        foreach (IPS_GetChildrenIDs($this->InstanceID) AS $object_ids) {
            $object = IPS_GetObject($object_ids);

            if (strstr($object['ObjectIdent'], '_' . $needle)) {
                return $object['ObjectIdent'];
            }
        }

        return '';
    }

    /**
     * get url of connect module
     * @return string
     */
    protected function _getConnectURL()
    {
        // get connect module
        if ($connect_id = @IPS_GetObjectIDByName('Connect', 0)) {
            $connect_url = CC_GetURL($connect_id);
            if (strlen($connect_url) > 10) {
                return $connect_url;
            }
        }

        return 'e.g. https://symcon.domain.com';
    }
}