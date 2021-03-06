<?php

namespace PushApi\Models;

use \Slim\Log;
use \PushApi\PushApi;
use \PushApi\System\IModel;
use \PushApi\PushApiException;
use \Illuminate\Database\Eloquent\Model as Eloquent;
use \Illuminate\Database\QueryException;
use \Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * @author Eloi Ballarà Madrid <eloi@tviso.com>
 * @copyright 2015 Eloi Ballarà Madrid <eloi@tviso.com>
 * @license http://www.opensource.org/licenses/mit-license.php The MIT License
 * Documentation @link https://push-api.readme.io/
 *
 * Model of the devices table, manages all the relationships and dependencies
 * that can be done on these table
 */
class Device extends Eloquent implements IModel
{
    const TYPE_EMAIL = 1;
    const TYPE_ANDROID = 2;
    const TYPE_IOS = 3;

    const STRING_TYPE_EMAIL = 'email';
    const STRING_TYPE_ANDROID = 'android';
    const STRING_TYPE_IOS = 'ios';

    public static $validStringTypes = [
        self::STRING_TYPE_EMAIL,
        self::STRING_TYPE_ANDROID,
        self::STRING_TYPE_IOS,
    ];

    public static $typeToString = [
        self::TYPE_EMAIL => self::STRING_TYPE_EMAIL,
        self::TYPE_ANDROID => self::STRING_TYPE_ANDROID,
        self::TYPE_IOS => self::STRING_TYPE_IOS,
    ];

    public static $stringToType = [
        self::STRING_TYPE_EMAIL => self::TYPE_EMAIL,
        self::STRING_TYPE_ANDROID => self::TYPE_ANDROID,
        self::STRING_TYPE_IOS => self::TYPE_IOS,
    ];

    public $timestamps = false;
    protected $fillable = array('type', 'user_id', 'reference');
    protected $guarded = array('id', 'created');
    protected $hidden = array('created');

    public static function getEmptyDataModel()
    {
        return [
            "id" => 0,
            "type" => "",
            "user_id" => 0,
            "reference" => "",
        ];
    }

    /**
     * Relationship 1-n to get an instance of the users table.
     * @return User Instance of User model.
     */
    public function user()
    {
        return $this->belongsTo('\PushApi\Models\User');
    }

    /**
     * Checks if device id exists and returns the device if true.
     * @param  int $id Device id.
     * @return Device/false
     */
    public static function checkExists($id)
    {
        try {
            $device = Device::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            PushApi::log(__METHOD__ . " - Error: " . PushApiException::NOT_FOUND, Log::DEBUG);
            return false;
        }

        return $device;
    }

    public static function checkDeviceOwnership($idUser, $idDevice)
    {
        try {
            $device = Device::where('id', $idDevice)->where('user_id', $idUser)->first();
        } catch (ModelNotFoundException $e) {
            PushApi::log(__METHOD__ . " - Error: " . PushApiException::NOT_FOUND, Log::DEBUG);
            return false;
        }

        if ($device) {
            return $device;
        }

        return false;
    }

    public static function generateFromModel($device)
    {
        $result = self::getEmptyDataModel();
        try {
            $result['id'] = (int) $device->id;
            $result['type'] = $device->type;
            $result['user_id'] = (int) $device->user_id;
            $result['reference'] = $device->reference;
        } catch (ModelNotFoundException $e) {
            PushApi::log(__METHOD__ . " - Error: " . PushApiException::NOT_FOUND, Log::DEBUG);
            return false;
        }

        return $result;
    }

    /**
     * Obtains device information given its reference with user id.
     * @param  int $idUser  User identification.
     * @param  string $reference
     * @return int/boolean  If user is found returns id, if not, returns false.
     */
    public static function getIdByReference($idUser, $reference)
    {
        $model = self::getEmptyDataModel();
        $device = Device::where('user_id', $idUser)->where('reference', $reference)->first();

        if ($device) {
            $model['id'] = (int) $device->id;
            $model['type'] = self::$typeToString[$device->type];
            $model['user_id'] = $idUser;
            $model['reference'] = $reference;
            return $model;
        }

        return false;
    }

    /**
     * Obtains all device information (even the non displayable) given its device reference.
     * @param  string $reference
     * @return int/boolean  If user is found returns id, if not, returns false.
     */
    public static function getFullDeviceInfoByReference($reference)
    {
        $model = self::getEmptyDataModel();
        $device = Device::where('reference', $reference)->first();

        if ($device) {
            $model['id'] = (int) $device->id;
            $model['type'] = self::$typeToString[$device->type];
            $model['user_id'] = (int) $device->user_id;
            $model['reference'] = $reference;
            return $model;
        }

        return false;
    }

    /**
     * Basic get device. Given its id and user id obtains device data if exists.
     * @param  int $idUser  User identification.
     * @param  string $idDevice
     * @return int/boolean  If user is found returns id, if not, returns false.
     */
    public static function getDevice($idUser, $idDevice)
    {
        $model = self::getEmptyDataModel();
        $device = self::checkDeviceOwnership($idUser, $idDevice);

        if ($device) {
            $model['id'] = (int) $device->id;
            $model['type'] = self::$typeToString[$device->type];
            $model['user_id'] = $idUser;
            $model['reference'] = $device->reference;
            return $model;
        }

        return false;
    }

    /**
     * Adds a new device referring the user and increases its devices counter.
     * It prevents to add duplicate values and updates smartphones device ids when a new user
     * is using the same smartphone.
     * @param  int $idUser  User identification.
     * @param  int/string $deviceType
     * @param  string $reference
     * @return boolean
     */
    public static function addDevice($idUser, $deviceType, $reference)
    {
        if (gettype($deviceType) == 'string' && isset(self::$stringToType[$deviceType])) {
            $deviceType = self::$stringToType[$deviceType];
        }

        if (!User::checkExists($idUser)) {
            PushApi::log(__METHOD__ . " - Error: " . PushApiException::NOT_FOUND, Log::DEBUG);
            return false;
        }

        /*
         * If device is a smartphone, it can be used by more than one user. If it has not removed
         * its notification after letting another user use its smartphone, it should be prevented
         * to send notifications of the previous user.
         */
        $device = self::getFullDeviceInfoByReference($reference);
        if ($device && ($deviceType != self::TYPE_EMAIL)) {
            // Removing data from the previous user
            self::removeDeviceById($device['user_id'], $device['id']);
        }

        // Adding device to user
        $device = new Device();
        $device->type = $deviceType;
        $device->user_id = $idUser;
        $device->reference = $reference;

        // Saving the device and preventing exception if it is duplicated
        try {
            $device->save();
        } catch (QueryException $e) {
            PushApi::log(__METHOD__ . " - Error: " . PushApiException::DUPLICATED_VALUE, Log::DEBUG);
            return false;
        }

        if (User::incrementDevice($idUser, $deviceType)) {
            return true;
        }

        return false;
    }

    /**
     * Deletes a device given that fits with all the params and decreases user devices counter.
     * Prevents to delete the last email address because user should have at least 1 email registered.
     * @param  int $idUser
     * @param  int/string $deviceType
     * @param  string $reference
     * @return boolean
     */
    public static function removeDeviceByParams($idUser, $deviceType, $reference)
    {
        if (gettype($deviceType) == 'string' && isset(self::$stringToType[$deviceType])) {
            $deviceType = self::$stringToType[$deviceType];
        }

        if (!$user = User::checkExists($idUser)) {
            PushApi::log(__METHOD__ . " - Error: " . PushApiException::DUPLICATED_VALUE, Log::DEBUG);
            return false;
        }

        // Preventing to delete the last email
        if ($deviceType == self::TYPE_EMAIL) {
            if ($user->email == 1) {
                PushApi::log(__METHOD__ . " - Cannot be removed the last email from user $idUser", Log::INFO);
                return false;
            }
        }

        // Searching if device exists and remove it (decrementing user counter value)
        $device = Device::where('user_id', $idUser)->where('type', $deviceType)->where('reference', $reference)->first();
        if ($device) {
            $device->delete();

            if (User::decrementDevice($idUser, $deviceType)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deletes a device using reference ids and decreases user devices counter.
     * Prevents to delete the last email address because user should have at least 1 email registered.
     * @param  int $idUser
     * @param  int $idDevice
     * @return boolean
     */
    public static function removeDeviceById($idUser, $idDevice)
    {
        if (!$user = User::checkExists($idUser)) {
            PushApi::log(__METHOD__ . " - Error: " . PushApiException::NOT_FOUND . " - User not found", Log::DEBUG);
            return false;
        }

        if (!$device = Device::checkExists($idDevice)) {
            PushApi::log(__METHOD__ . " - Error: " . PushApiException::NOT_FOUND . " - Device not found", Log::DEBUG);
            return false;
        }

        if (!self::checkDeviceOwnership($idUser, $idDevice)) {
            PushApi::log(__METHOD__ . " - Error: " . PushApiException::NOT_FOUND . " - User does not own the device", Log::DEBUG);
            return false;
        }

        // Preventing to delete the last email
        if ($device->type == self::TYPE_EMAIL) {
            if ($user->email == 1) {
                PushApi::log(__METHOD__ . " - Cannot be removed the last email from user $idUser", Log::INFO);
                return false;
            }
        }

        // Removing the device and decrementing user counter value
        $device->delete();

        if (User::decrementDevice($idUser, $device->type)) {
            return true;
        }

        return false;
    }

    /**
     * Deletes all devices of the target user id.
     * @param  int $idUser User identification.
     * @param  string $type Device type.
     * @return boolean
     */
    public static function deleteDevicesByType($idUser, $type)
    {
        if ($type == self::STRING_TYPE_EMAIL) {
            PushApi::log(__METHOD__ . " - Error: " . PushApiException::INVALID_ACTION . " - Cannot remove all emails", Log::DEBUG);
            return false;
        }

        $devices = Device::where('user_id', $idUser)->get();

        // Deleting only the devices of the same type and decrementing the user device counter
        foreach ($devices as $device) {
            if (self::$typeToString[$device->type] == $type) {
                $device->delete();
                User::decrementDevice($idUser, $device->type);
            }
        }

        return true;
    }

     /**
     * Deletes all devices of the target user id.
     * @param  int $idUser User identification.
     * @return boolean
     */
    public static function deleteAllDevices($idUser)
    {
        $devices = Device::where('user_id', $idUser)->get();

        foreach ($devices as $device) {
            $device->delete();
        }

        return true;
    }
}