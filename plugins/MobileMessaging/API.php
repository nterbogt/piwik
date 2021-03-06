<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik_Plugins
 * @package MobileMessaging
 */
namespace Piwik\Plugins\MobileMessaging;

use Piwik\Common;
use Piwik\Option;
use Piwik\Piwik;
use Piwik\Plugins\MobileMessaging\SMSProvider;
use Piwik\Plugins\ScheduledReports\API as APIScheduledReports;

/**
 * The MobileMessaging API lets you manage and access all the MobileMessaging plugin features including :
 *  - manage SMS API credential
 *  - activate phone numbers
 *  - check remaining credits
 *  - send SMS
 * @package MobileMessaging
 * @method static \Piwik\Plugins\MobileMessaging\API getInstance()
 */
class API extends \Piwik\Plugin\API
{
    const VERIFICATION_CODE_LENGTH = 5;
    const SMS_FROM = 'Piwik';

    /**
     * @param string $provider
     * @return SMSProvider
     */
    static private function getSMSProviderInstance($provider)
    {
        return SMSProvider::factory($provider);
    }

    /**
     * determine if SMS API credential are available for the current user
     *
     * @return bool true if SMS API credential are available for the current user
     */
    public function areSMSAPICredentialProvided()
    {
        Piwik::checkUserHasSomeViewAccess();

        $credential = $this->getSMSAPICredential();
        return isset($credential[MobileMessaging::API_KEY_OPTION]);
    }

    private function getSMSAPICredential()
    {
        $settings = $this->getCredentialManagerSettings();
        return array(
            MobileMessaging::PROVIDER_OPTION =>
                isset($settings[MobileMessaging::PROVIDER_OPTION]) ? $settings[MobileMessaging::PROVIDER_OPTION] : null,
            MobileMessaging::API_KEY_OPTION  =>
                isset($settings[MobileMessaging::API_KEY_OPTION]) ? $settings[MobileMessaging::API_KEY_OPTION] : null,
        );
    }

    /**
     * return the SMS API Provider for the current user
     *
     * @return string SMS API Provider
     */
    public function getSMSProvider()
    {
        $this->checkCredentialManagementRights();
        $credential = $this->getSMSAPICredential();
        return $credential[MobileMessaging::PROVIDER_OPTION];
    }

    /**
     * set the SMS API credential
     *
     * @param string $provider SMS API provider
     * @param string $apiKey API Key
     *
     * @return bool true if SMS API credential were validated and saved, false otherwise
     */
    public function setSMSAPICredential($provider, $apiKey)
    {
        $this->checkCredentialManagementRights();

        $smsProviderInstance = self::getSMSProviderInstance($provider);
        $smsProviderInstance->verifyCredential($apiKey);

        $settings = $this->getCredentialManagerSettings();

        $settings[MobileMessaging::PROVIDER_OPTION] = $provider;
        $settings[MobileMessaging::API_KEY_OPTION] = $apiKey;

        $this->setCredentialManagerSettings($settings);

        return true;
    }

    /**
     * add phone number
     *
     * @param string $phoneNumber
     *
     * @return bool true
     */
    public function addPhoneNumber($phoneNumber)
    {
        Piwik::checkUserIsNotAnonymous();

        $phoneNumber = self::sanitizePhoneNumber($phoneNumber);

        $verificationCode = "";
        for ($i = 0; $i < self::VERIFICATION_CODE_LENGTH; $i++) {
            $verificationCode .= mt_rand(0, 9);
        }

        $smsText = Piwik::translate(
            'MobileMessaging_VerificationText',
            array(
                 $verificationCode,
                 Piwik::translate('General_Settings'),
                 Piwik::translate('MobileMessaging_SettingsMenu')
            )
        );

        $this->sendSMS($smsText, $phoneNumber, self::SMS_FROM);

        $phoneNumbers = $this->retrievePhoneNumbers();
        $phoneNumbers[$phoneNumber] = $verificationCode;
        $this->savePhoneNumbers($phoneNumbers);

        $this->increaseCount(MobileMessaging::PHONE_NUMBER_VALIDATION_REQUEST_COUNT_OPTION, $phoneNumber);

        return true;
    }

    /**
     * sanitize phone number
     *
     * @ignore
     * @param string $phoneNumber
     * @return string sanitized phone number
     */
    public static function sanitizePhoneNumber($phoneNumber)
    {
        return str_replace(' ', '', $phoneNumber);
    }

    /**
     * send a SMS
     *
     * @param string $content
     * @param string $phoneNumber
     * @param string $from
     * @return bool true
     * @ignore
     */
    public function sendSMS($content, $phoneNumber, $from)
    {
        Piwik::checkUserIsNotAnonymous();

        $credential = $this->getSMSAPICredential();
        $SMSProvider = self::getSMSProviderInstance($credential[MobileMessaging::PROVIDER_OPTION]);
        $SMSProvider->sendSMS(
            $credential[MobileMessaging::API_KEY_OPTION],
            $content,
            $phoneNumber,
            $from
        );

        $this->increaseCount(MobileMessaging::SMS_SENT_COUNT_OPTION, $phoneNumber);

        return true;
    }

    /**
     * get remaining credit
     *
     * @return string remaining credit
     */
    public function getCreditLeft()
    {
        $this->checkCredentialManagementRights();

        $credential = $this->getSMSAPICredential();
        $SMSProvider = self::getSMSProviderInstance($credential[MobileMessaging::PROVIDER_OPTION]);
        return $SMSProvider->getCreditLeft(
            $credential[MobileMessaging::API_KEY_OPTION]
        );
    }

    /**
     * remove phone number
     *
     * @param string $phoneNumber
     *
     * @return bool true
     */
    public function removePhoneNumber($phoneNumber)
    {
        Piwik::checkUserIsNotAnonymous();

        $phoneNumbers = $this->retrievePhoneNumbers();
        unset($phoneNumbers[$phoneNumber]);
        $this->savePhoneNumbers($phoneNumbers);

        // remove phone number from reports
        $APIScheduledReports = APIScheduledReports::getInstance();
        $reports = $APIScheduledReports->getReports(
            $idSite = false,
            $period = false,
            $idReport = false,
            $ifSuperUserReturnOnlySuperUserReports = $this->getDelegatedManagement()
        );

        foreach ($reports as $report) {
            if ($report['type'] == MobileMessaging::MOBILE_TYPE) {
                $reportParameters = $report['parameters'];
                $reportPhoneNumbers = $reportParameters[MobileMessaging::PHONE_NUMBERS_PARAMETER];
                $updatedPhoneNumbers = array();
                foreach ($reportPhoneNumbers as $reportPhoneNumber) {
                    if ($reportPhoneNumber != $phoneNumber) {
                        $updatedPhoneNumbers[] = $reportPhoneNumber;
                    }
                }

                if (count($updatedPhoneNumbers) != count($reportPhoneNumbers)) {
                    $reportParameters[MobileMessaging::PHONE_NUMBERS_PARAMETER] = $updatedPhoneNumbers;

                    // note: reports can end up without any recipients
                    $APIScheduledReports->updateReport(
                        $report['idreport'],
                        $report['idsite'],
                        $report['description'],
                        $report['period'],
                        $report['hour'],
                        $report['type'],
                        $report['format'],
                        $report['reports'],
                        $reportParameters
                    );
                }
            }
        }

        return true;
    }

    private function retrievePhoneNumbers()
    {
        $settings = $this->getCurrentUserSettings();

        $phoneNumbers = array();
        if (isset($settings[MobileMessaging::PHONE_NUMBERS_OPTION])) {
            $phoneNumbers = $settings[MobileMessaging::PHONE_NUMBERS_OPTION];
        }

        return $phoneNumbers;
    }

    private function savePhoneNumbers($phoneNumbers)
    {
        $settings = $this->getCurrentUserSettings();

        $settings[MobileMessaging::PHONE_NUMBERS_OPTION] = $phoneNumbers;

        $this->setCurrentUserSettings($settings);
    }

    private function increaseCount($option, $phoneNumber)
    {
        $settings = $this->getCurrentUserSettings();

        $counts = array();
        if (isset($settings[$option])) {
            $counts = $settings[$option];
        }

        $countToUpdate = 0;
        if (isset($counts[$phoneNumber])) {
            $countToUpdate = $counts[$phoneNumber];
        }

        $counts[$phoneNumber] = $countToUpdate + 1;

        $settings[$option] = $counts;

        $this->setCurrentUserSettings($settings);
    }

    /**
     * validate phone number
     *
     * @param string $phoneNumber
     * @param string $verificationCode
     *
     * @return bool true if validation code is correct, false otherwise
     */
    public function validatePhoneNumber($phoneNumber, $verificationCode)
    {
        Piwik::checkUserIsNotAnonymous();

        $phoneNumbers = $this->retrievePhoneNumbers();

        if (isset($phoneNumbers[$phoneNumber])) {
            if ($verificationCode == $phoneNumbers[$phoneNumber]) {

                $phoneNumbers[$phoneNumber] = null;
                $this->savePhoneNumbers($phoneNumbers);
                return true;
            }
        }

        return false;
    }

    /**
     * get phone number list
     *
     * @return array $phoneNumber => $isValidated
     * @ignore
     */
    public function getPhoneNumbers()
    {
        Piwik::checkUserIsNotAnonymous();

        $rawPhoneNumbers = $this->retrievePhoneNumbers();

        $phoneNumbers = array();
        foreach ($rawPhoneNumbers as $phoneNumber => $verificationCode) {
            $phoneNumbers[$phoneNumber] = self::isActivated($verificationCode);
        }

        return $phoneNumbers;
    }

    /**
     * get activated phone number list
     *
     * @return array $phoneNumber
     * @ignore
     */
    public function getActivatedPhoneNumbers()
    {
        Piwik::checkUserIsNotAnonymous();

        $phoneNumbers = $this->retrievePhoneNumbers();

        $activatedPhoneNumbers = array();
        foreach ($phoneNumbers as $phoneNumber => $verificationCode) {
            if (self::isActivated($verificationCode)) {
                $activatedPhoneNumbers[] = $phoneNumber;
            }
        }

        return $activatedPhoneNumbers;
    }

    private static function isActivated($verificationCode)
    {
        return $verificationCode === null;
    }

    /**
     * delete the SMS API credential
     *
     * @return bool true
     */
    public function deleteSMSAPICredential()
    {
        $this->checkCredentialManagementRights();

        $settings = $this->getCredentialManagerSettings();

        $settings[MobileMessaging::API_KEY_OPTION] = null;

        $this->setCredentialManagerSettings($settings);

        return true;
    }

    private function checkCredentialManagementRights()
    {
        $this->getDelegatedManagement() ? Piwik::checkUserIsNotAnonymous() : Piwik::checkUserIsSuperUser();
    }

    private function setUserSettings($user, $settings)
    {
        Option::set(
            $user . MobileMessaging::USER_SETTINGS_POSTFIX_OPTION,
            Common::json_encode($settings)
        );
    }

    private function setCurrentUserSettings($settings)
    {
        $this->setUserSettings(Piwik::getCurrentUserLogin(), $settings);
    }

    private function setCredentialManagerSettings($settings)
    {
        $this->setUserSettings($this->getCredentialManagerLogin(), $settings);
    }

    private function getCredentialManagerLogin()
    {
        return $this->getDelegatedManagement() ? Piwik::getCurrentUserLogin() : Piwik::getSuperUserLogin();
    }

    private function getUserSettings($user)
    {
        $optionIndex = $user . MobileMessaging::USER_SETTINGS_POSTFIX_OPTION;
        $userSettings = Option::get($optionIndex);

        if (empty($userSettings)) {
            $userSettings = array();
        } else {
            $userSettings = Common::json_decode($userSettings, true);
        }

        return $userSettings;
    }

    private function getCredentialManagerSettings()
    {
        return $this->getUserSettings($this->getCredentialManagerLogin());
    }

    private function getCurrentUserSettings()
    {
        return $this->getUserSettings(Piwik::getCurrentUserLogin());
    }

    /**
     * Specify if normal users can manage their own SMS API credential
     *
     * @param bool $delegatedManagement false if SMS API credential only manageable by super admin, true otherwise
     */
    public function setDelegatedManagement($delegatedManagement)
    {
        Piwik::checkUserIsSuperUser();
        Option::set(MobileMessaging::DELEGATED_MANAGEMENT_OPTION, $delegatedManagement);
    }

    /**
     * Determine if normal users can manage their own SMS API credential
     *
     * @return bool false if SMS API credential only manageable by super admin, true otherwise
     */
    public function getDelegatedManagement()
    {
        Piwik::checkUserHasSomeViewAccess();
        return Option::get(MobileMessaging::DELEGATED_MANAGEMENT_OPTION) == 'true';
    }
}
