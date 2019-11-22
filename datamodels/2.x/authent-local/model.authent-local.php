<?php
// Copyright (C) 2010-2012 Combodo SARL
//
//   This file is part of iTop.
//
//   iTop is free software; you can redistribute it and/or modify	
//   it under the terms of the GNU Affero General Public License as published by
//   the Free Software Foundation, either version 3 of the License, or
//   (at your option) any later version.
//
//   iTop is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU Affero General Public License for more details.
//
//   You should have received a copy of the GNU Affero General Public License
//   along with iTop. If not, see <http://www.gnu.org/licenses/>


/**
 * Authent Local
 * User authentication Module, password stored in the local database
 *
 * @copyright   Copyright (C) 2010-2012 Combodo SARL
 * @license     http://opensource.org/licenses/AGPL-3.0
 */


class UserLocalPasswordValidity
{
	/** @var bool */
	protected $m_bPasswordValidity;
	/** @var string|null */
	protected $m_sPasswordValidityMessage;

	/**
	 * UserLocalPasswordValidity constructor.
	 *
	 * @param bool $m_bPasswordValidity
	 * @param string $m_sPasswordValidityMessage
	 */
	public function __construct($m_bPasswordValidity, $m_sPasswordValidityMessage = null)
	{
		$this->m_bPasswordValidity = $m_bPasswordValidity;
		$this->m_sPasswordValidityMessage = $m_sPasswordValidityMessage;
	}

	/**
	 * @return bool
	 */
	public function isPasswordValid()
	{
		return $this->m_bPasswordValidity;
	}


	/**
	 * @return string
	 */
	public function getPasswordValidityMessage()
	{
		return $this->m_sPasswordValidityMessage;
	}
}

class UserLocal extends UserInternal
{
	/** @var UserLocalPasswordValidity|null */
	protected $m_oPasswordValidity = null;

	public static function Init()
	{
		$aParams = array
		(
			"category" => "addon/authentication,grant_by_profile",
			"key_type" => "autoincrement",
			"name_attcode" => "login",
			"state_attcode" => "",
			"reconc_keys" => array('login'),
			"db_table" => "priv_user_local",
			"db_key_field" => "id",
			"db_finalclass_field" => "",
			"display_template" => "",
		);
		MetaModel::Init_Params($aParams);
		MetaModel::Init_InheritAttributes();

		MetaModel::Init_AddAttribute(new AttributeOneWayPassword("password", array("allowed_values"=>null, "sql"=>"pwd", "default_value"=>null, "is_null_allowed"=>false, "depends_on"=>array())));

		// Display lists
		MetaModel::Init_SetZListItems('details', array('contactid', 'org_id', 'email', 'login', 'password', 'language', 'status', 'profile_list', 'allowed_org_list')); // Attributes to be displayed for the complete details
		MetaModel::Init_SetZListItems('list', array('first_name', 'last_name', 'login', 'org_id')); // Attributes to be displayed for a list
		// Search criteria
		MetaModel::Init_SetZListItems('standard_search', array('login', 'contactid', 'status', 'org_id')); // Criteria of the std search form
	}

	public function CheckCredentials($sPassword)
	{
		$oPassword = $this->Get('password'); // ormPassword object
		// Cannot compare directly the values since they are hashed, so
		// Let's ask the password to compare the hashed values
		if ($oPassword->CheckPassword($sPassword))
		{
			return true;
		}
		return false;
	}

	public function TrustWebServerContext()
	{
		return true;
	}

	public function CanChangePassword()
	{
		if (MetaModel::GetConfig()->Get('demo_mode'))
		{
			return false;
		}
		return true;
	}

	public function ChangePassword($sOldPassword, $sNewPassword)
	{
		/** @var \ormPassword $oPassword */
		$oPassword = $this->Get('password');
		// Cannot compare directly the values since they are hashed, so
		// Let's ask the password to compare the hashed values
		if ($oPassword->CheckPassword($sOldPassword))
		{
			$this->SetPassword($sNewPassword);
			return $this->IsPasswordValid();
		}
		return false;
	}

	/**
	 * Use with care!
	 */	 	
	public function SetPassword($sNewPassword)
	{
		$this->Set('password', $sNewPassword);
		$this->DBUpdate();
	}

	public function Set($sAttCode, $value)
	{
		$result = parent::Set($sAttCode, $value);

		if ('password' == $sAttCode)
		{
			$this->ValidatePassword($value);
		}

		return $result;
	}

	public function IsPasswordValid()
	{
		return (empty($this->m_oPasswordValidity)) || ($this->m_oPasswordValidity->isPasswordValid());
	}

	/**
	 * set the $m_oPasswordValidity
	 *
	 * @param string $proposedValue
	 * @param \Config|null $config
	 *
	 * @return void
	 */
	public function ValidatePassword($proposedValue, $config = null)
	{
		if (null == $config)
		{
			$config =  MetaModel::GetConfig();
		}

		$aPasswordValidationClasses = $config->GetModuleSetting('authent-local', 'password_validation.classes');
		if (empty($aPasswordValidationClasses))
		{
			$aPasswordValidationClasses = array();
		}

		$sUserPasswordPolicyRegexPattern = $config->GetModuleSetting('authent-local', 'password_validation.pattern');
		if ($sUserPasswordPolicyRegexPattern)
		{
			if (array_key_exists('UserPasswordPolicyRegex', $aPasswordValidationClasses))
			{
				$this->m_oPasswordValidity = new UserLocalPasswordValidity(
					false,
					"Invalid configuration: 'UserPasswordPolicyRegex' was defined twice (once into UserLocal.password_validation_advanced, once into UserLocal.password_validation)."
				);
				return;
			}

			$aPasswordValidationClasses['UserPasswordPolicyRegex'] = array('pattern' => $sUserPasswordPolicyRegexPattern);
		}

		foreach ($aPasswordValidationClasses as $sClass => $aOptions)
		{
			if (!is_subclass_of($sClass, 'UserLocalPasswordValidator'))
			{
				$this->m_oPasswordValidity = new UserLocalPasswordValidity(
					false,
					"Invalid configuration: '{$sClass}' must implements ".UserLocalPasswordValidator::class
				);
				return;
			}

			/** @var \UserLocalPasswordValidator */
			$oInstance = new $sClass();

			$this->m_oPasswordValidity = $oInstance->ValidatePassword($proposedValue, $aOptions, $this);

			if (!$this->m_oPasswordValidity->isPasswordValid())
			{
				return;
			}
		}
	}

	public function DoCheckToWrite()
	{
		if (! $this->IsPasswordValid())
		{
			$this->m_aCheckIssues[] = $this->m_oPasswordValidity->getPasswordValidityMessage();
		}

		parent::DoCheckToWrite();
	}

	/**
	 * Returns the set of flags (OPT_ATT_HIDDEN, OPT_ATT_READONLY, OPT_ATT_MANDATORY...)
	 * for the given attribute in the current state of the object
	 *
	 * @param $sAttCode string $sAttCode The code of the attribute
	 * @param $aReasons array To store the reasons why the attribute is read-only (info about the synchro replicas)
	 * @param $sTargetState string The target state in which to evaluate the flags, if empty the current state will be used
	 *
	 * @return integer Flags: the binary combination of the flags applicable to this attribute
	 * @throws \CoreException
	 */
	public function GetAttributeFlags($sAttCode, &$aReasons = array(), $sTargetState = '')
	{
		$iFlags = parent::GetAttributeFlags($sAttCode, $aReasons, $sTargetState);
		if (MetaModel::GetConfig()->Get('demo_mode'))
		{
			if (strpos('contactid,login,language,password,status,profile_list,allowed_org_list', $sAttCode) !== false)
			{
				// contactid and allowed_org_list are disabled to make sure the portal remains accessible 
				$aReasons[] = 'Sorry, this attribute is read-only in the demonstration mode!';
				$iFlags |= OPT_ATT_READONLY;
			}
		}
		return $iFlags;
	}
}



interface UserLocalPasswordValidator
{
	public function __construct();

	/**
	 * @param string $proposedValue
	 * @param array $aOptions
	 * @param UserLocal $oUserLocal
	 *
	 * @return UserLocalPasswordValidity
	 */
	public function ValidatePassword($proposedValue, $aOptions, UserLocal $oUserLocal);
}

class UserPasswordPolicyRegex implements UserLocalPasswordValidator
{
	public function __construct()
	{
	}

	/**
	 * @param string $proposedValue
	 * @param array $aOptions
	 * @param UserLocal $oUserLocal
	 *
	 * @return UserLocalPasswordValidity
	 */
	public function ValidatePassword($proposedValue, $aOptions, UserLocal $oUserLocal)
	{

		if (! array_key_exists('pattern', $aOptions) )
		{
			return new UserLocalPasswordValidity(
				false,
				"Invalid configuration: key 'pattern' is mandatory"
			);
		}

		$sPattern = $aOptions['pattern'];
		if ('' == $sPattern)
		{
			return new UserLocalPasswordValidity(true);
		}

		$isMatched = preg_match("/{$sPattern}/", $proposedValue);
		if ($isMatched === false)
		{
			return new UserLocalPasswordValidity(
				false,
				'Unknown error : Failed to check the password, please verify the password\'s Data Model.'
			);
		}

		if ($isMatched === 1)
		{
			return new UserLocalPasswordValidity(true);
		}

		$sMessage = Dict::S('Error:UserLocalPasswordValidator:UserPasswordPolicyRegex/validation_failed');

		return new UserLocalPasswordValidity(
			false,
			$sMessage
		);
	}
}

