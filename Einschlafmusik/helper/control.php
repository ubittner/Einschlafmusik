<?php

/**
 * @project       Einschlafmusik/Einschlafmusik/helper
 * @file          control.php
 * @author        Ulrich Bittner
 * @copyright     2023 Ulrich Bittner
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 */

/** @noinspection PhpVoidFunctionResultUsedInspection */

declare(strict_types=1);

trait Control
{
    /**
     * Toggles the fall asleep music off or on.
     *
     * @param bool $State
     * false =  off,
     * true =   on
     *
     * @return bool
     * false =  an error occurred,
     * true =   successful
     *
     * @throws Exception
     */
    public function ToggleFallAsleepMusic(bool $State): bool
    {
        $debugText = 'Einschlafmusik ausschalten';
        if ($State) {
            $debugText = 'Einschlafmusik einschalten';
        }
        $this->SendDebug(__FUNCTION__, $debugText, 0);
        //Off
        if (!$State) {
            $this->SetValue('FallAsleepMusic', false);
            IPS_SetDisabled($this->GetIDForIdent('Volume'), false);
            IPS_SetDisabled($this->GetIDForIdent('Presets'), false);
            IPS_SetDisabled($this->GetIDForIdent('Duration'), false);
            $this->SetValue('ProcessFinished', '');
            @IPS_SetHidden($this->GetIDForIdent('ProcessFinished'), true);
            $this->WriteAttributeInteger('CyclingVolume', 0);
            $this->WriteAttributeInteger('EndTime', 0);
            $this->SetTimerInterval('DecreaseVolume', 0);
        } //On
        else {
            if (!$this->CheckDevicePowerID()) {
                return false;
            }
            if (!$this->CheckDeviceVolumeID()) {
                return false;
            }
            //Timestamp
            $timestamp = time() + $this->GetValue('Duration') * 60;
            //Set values
            $this->SetValue('FallAsleepMusic', true);
            IPS_SetDisabled($this->GetIDForIdent('Volume'), true);
            IPS_SetDisabled($this->GetIDForIdent('Presets'), true);
            IPS_SetDisabled($this->GetIDForIdent('Duration'), true);
            $this->SetValue('ProcessFinished', date('d.m.Y, H:i:s', $timestamp));
            @IPS_SetHidden($this->GetIDForIdent('ProcessFinished'), false);
            //Set attributes
            $volume = $this->GetValue('Volume');
            $this->WriteAttributeInteger('CyclingVolume', $volume);
            $this->SendDebug(__FUNCTION__, 'Lautstärke: ' . $volume, 0);
            $this->WriteAttributeInteger('EndTime', $timestamp);
            //Set device volume
            $setDeviceVolume = @RequestAction($this->ReadPropertyInteger('DeviceVolume'), $volume);
            //Try again
            if (!$setDeviceVolume) {
                @RequestAction($this->ReadPropertyInteger('DeviceVolume'), $volume);
            }
            //Set device preset
            $powerOn = true;
            if ($this->CheckDevicePresetsID()) {
                $devicePresetID = $this->ReadPropertyInteger('DevicePresets');
                $preset = $this->GetValue('Presets');
                if ($preset > 0) {
                    $powerOn = false;
                    $setDevicePreset = @RequestAction($devicePresetID, $preset);
                    $this->SendDebug(__FUNCTION__, 'Preset: ' . $preset, 0);
                    //Try again
                    if (!$setDevicePreset) {
                        @RequestAction($devicePresetID, $preset);
                    }
                }
            }
            //Power on device
            if ($powerOn) {
                $this->SendDebug(__FUNCTION__, 'Gerät einschalten', 0);
                $powerOnDevice = @RequestAction($this->ReadPropertyInteger('DevicePower'), true);
                //Try again
                if (!$powerOnDevice) {
                    @RequestAction($this->ReadPropertyInteger('DevicePower'), true);
                }
            }
            //Set next cycle
            $this->SetTimerInterval('DecreaseVolume', $this->CalculateNextCycle() * 1000);
        }
        return true;
    }

    /**
     * Decreases the volume of the device.
     *
     * @return void
     * @throws Exception
     */
    public function DecreaseVolume(): void
    {
        if (!$this->CheckDevicePowerID()) {
            return;
        }
        if (!$this->CheckDeviceVolumeID()) {
            return;
        }
        $cyclingVolume = $this->ReadAttributeInteger('CyclingVolume');
        //Last cycle
        if ($cyclingVolume == 1) {
            $this->ToggleFallAsleepMusic(false);
            //Power off device
            $this->SendDebug(__FUNCTION__, 'Gerät ausschalten', 0);
            $powerOffDevice = @RequestAction($this->ReadPropertyInteger('DevicePower'), false);
            //Try again
            if (!$powerOffDevice) {
                @RequestAction($this->ReadPropertyInteger('DevicePower'), false);
            }
            return;
        }
        //Cycle
        $actualDeviceVolume = GetValue($this->ReadPropertyInteger('DeviceVolume'));
        if ($actualDeviceVolume > 1) {
            //Decrease volume
            $decreasedVolume = $cyclingVolume - 1;
            $this->WriteAttributeInteger('CyclingVolume', $decreasedVolume);
            $this->SendDebug(__FUNCTION__, 'Lautstärke: ' . $decreasedVolume, 0);
            $setDeviceVolume = @RequestAction($this->ReadPropertyInteger('DeviceVolume'), $decreasedVolume);
            //Try again
            if (!$setDeviceVolume) {
                @RequestAction($this->ReadPropertyInteger('DeviceVolume'), $decreasedVolume);
            }
            //Set next cycle
            $this->SetTimerInterval('DecreaseVolume', $this->CalculateNextCycle() * 1000);
        }
    }

    #################### Private

    /**
     * Calculates the next cycle.
     *
     * @return int
     * @throws Exception
     */
    private function CalculateNextCycle(): int
    {
        if (!$this->CheckDeviceVolumeID()) {
            return 0;
        }
        $deviceVolumeID = $this->ReadPropertyInteger('DeviceVolume');
        $actualDeviceVolume = GetValue($deviceVolumeID);
        $dividend = $this->ReadAttributeInteger('EndTime') - time();
        //Check dividend
        if ($dividend <= 0) {
            $this->ToggleFallAsleepMusic(false);
            return 0;
        }
        return intval(round($dividend / $actualDeviceVolume));
    }

    /**
     * Checks for an existing device power id.
     *
     * @return bool
     * false =  doesn't exist,
     * true =   exists
     *
     * @throws Exception
     */
    private function CheckDevicePowerID(): bool
    {
        $devicePowerID = $this->ReadPropertyInteger('DevicePower');
        if ($devicePowerID <= 1 || @!IPS_ObjectExists($devicePowerID)) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Power (Aus/An) ist nicht vorhanden!', 0);
            return false;
        }
        return true;
    }

    /**
     * Checks for an existing device volume id.
     *
     * @return bool
     * false =  doesn't exist,
     * true =   exists
     *
     * @throws Exception
     */
    private function CheckDeviceVolumeID(): bool
    {
        $deviceVolumeID = $this->ReadPropertyInteger('DeviceVolume');
        if ($deviceVolumeID <= 1 || @!IPS_ObjectExists($deviceVolumeID)) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Lautstärke ist nicht vorhanden!', 0);
            return false;
        }
        return true;
    }

    /**
     * Checks for an existing device presets id.
     *
     * @return bool
     * false =  doesn't exist,
     * true =   exists
     *
     * @throws Exception
     */
    private function CheckDevicePresetsID(): bool
    {
        $devicePresetsID = $this->ReadPropertyInteger('DevicePresets');
        if ($devicePresetsID <= 1 || @!IPS_ObjectExists($devicePresetsID)) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Presets ist nicht vorhanden!', 0);
            return false;
        }
        return true;
    }
}