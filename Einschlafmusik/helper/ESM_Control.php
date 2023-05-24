<?php

/**
 * @project       Einschlafmusik/Einschlafmusik
 * @file          ESM_Control.php
 * @author        Ulrich Bittner
 * @copyright     2023 Ulrich Bittner
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 */

declare(strict_types=1);

trait ESM_Control
{
    /**
     * Toggles the sleep timer off or on.
     *
     * @param bool $State
     * false =  off
     * true =   on
     *
     * @param integer $Mode
     * 0 =  Manually,
     * 1 =  Weekly Schedule
     *
     * @return bool
     * false =  an error occurred,
     * true =   successful
     *
     * @throws Exception
     */
    public function ToggleSleepTimer(bool $State, int $Mode = 0): bool
    {
        $this->SendDebug(__FUNCTION__, 'wird ausgeführt.', 0);
        $this->SendDebug(__FUNCTION__, 'Status: ' . json_encode($State), 0);
        $this->SendDebug(__FUNCTION__, 'Modus: ' . $Mode, 0);

        //Off
        if (!$State) {
            $this->SetValue('SleepTimer', false);
            IPS_SetDisabled($this->GetIDForIdent('Volume'), false);
            IPS_SetDisabled($this->GetIDForIdent('Presets'), false);
            IPS_SetDisabled($this->GetIDForIdent('Duration'), false);
            $this->SetValue('ProcessFinished', '');
            @IPS_SetHidden($this->GetIDForIdent('ProcessFinished'), true);
            $this->WriteAttributeInteger('CyclingVolume', 0);
            $this->WriteAttributeInteger('EndTime', 0);
            $this->SetTimerInterval('DecreaseVolume', 0);
        }

        //On
        else {
            $audioStatusID = $this->ReadPropertyInteger('AudioStatus');
            if ($audioStatusID <= 1 || @!IPS_ObjectExists($audioStatusID)) {
                $this->SendDebug(__FUNCTION__, 'Abbruch, Audio Status ist nicht vorhanden!', 0);
                return false;
            }

            $audioVolumeID = $this->ReadPropertyInteger('AudioVolume');
            if ($audioVolumeID <= 1 || @!IPS_ObjectExists($audioVolumeID)) {
                $this->SendDebug(__FUNCTION__, 'Abbruch, Audio Lautstärke ist nicht vorhanden!', 0);
                return false;
            }

            //Manually
            if ($Mode == 0) {
                $volume = $this->GetValue('Volume');
                $preset = $this->GetValue('Presets');
                $timestamp = time() + $this->GetValue('Duration') * 60;
            }

            //Weekly schedule
            else {
                $day = date('N');

                //Weekday
                if ($day >= 1 && $day <= 5) {
                    $volume = $this->ReadPropertyInteger('WeekdayVolume');
                    $preset = $this->ReadPropertyInteger('WeekdayPreset');
                    $timestamp = time() + $this->ReadPropertyInteger('WeekdayDuration') * 60;
                }

                //Weekend
                else {
                    $volume = $this->ReadPropertyInteger('WeekendVolume');
                    $preset = $this->ReadPropertyInteger('WeekendPreset');
                    $timestamp = time() + $this->ReadPropertyInteger('WeekendDuration') * 60;
                }
            }

            //Set values
            $this->SetValue('SleepTimer', true);
            IPS_SetDisabled($this->GetIDForIdent('Volume'), true);
            IPS_SetDisabled($this->GetIDForIdent('Presets'), true);
            IPS_SetDisabled($this->GetIDForIdent('Duration'), true);
            $this->SetValue('ProcessFinished', date('d.m.Y, H:i:s', $timestamp));
            @IPS_SetHidden($this->GetIDForIdent('ProcessFinished'), false);

            //Set attributes
            $this->WriteAttributeInteger('CyclingVolume', $volume - 1);
            $this->WriteAttributeInteger('EndTime', $timestamp);

            //Set audio values
            $audioPresetID = $this->ReadPropertyInteger('AudioPreset');
            if ($audioPresetID > 1 && @IPS_ObjectExists($audioPresetID)) {
                @RequestAction($audioPresetID, $preset);
            }
            @RequestAction($audioVolumeID, $volume);
            @RequestAction($audioStatusID, true);

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
        $this->SendDebug(__FUNCTION__, 'wird ausgeführt.', 0);

        $audioStatusID = $this->ReadPropertyInteger('AudioStatus');
        if ($audioStatusID <= 1 || @!IPS_ObjectExists($audioStatusID)) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Audio Status ist nicht vorhanden!', 0);
        }

        $audioVolumeID = $this->ReadPropertyInteger('AudioVolume');
        if ($audioVolumeID <= 1 || @!IPS_ObjectExists($audioVolumeID)) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Audio Lautstärke ist nicht vorhanden!', 0);
        }

        $actualVolume = GetValue($audioVolumeID);

        //Abort, if the device was already switched off and user don't want to wait until cycling end
        if (!GetValue($audioStatusID) || $actualVolume == 0) {
            $this->ToggleSleepTimer(false);
            return;
        }

        //Abort, if actual volume is higher than the last cycling Volume
        if ($actualVolume > $this->ReadAttributeInteger('CyclingVolume') + 1) {
            $this->ToggleSleepTimer(false);
            return;
        }

        //Last cycle
        if ($actualVolume == 1) {
            //Switch device off
            @RequestAction($audioStatusID, false);
            $this->ToggleSleepTimer(false);
        }

        //Cycle
        if ($actualVolume > 1) {
            //Decrease Volume
            @RequestAction($audioVolumeID, $actualVolume - 1);
            $this->WriteAttributeInteger('CyclingVolume', $actualVolume - 1);
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
        $this->SendDebug(__FUNCTION__, 'wird ausgeführt.', 0);
        $audioVolumeID = $this->ReadPropertyInteger('AudioVolume');
        if ($audioVolumeID <= 1 || @!IPS_ObjectExists($audioVolumeID)) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Audio Lautstärke ist nicht vorhanden!', 0);
            return 0;
        }
        $deviceVolume = GetValue($audioVolumeID);
        if ($deviceVolume == 0) {
            $this->ToggleSleepTimer(false);
        }
        $dividend = $this->ReadAttributeInteger('EndTime') - time();
        //Check dividend
        if ($dividend <= 0) {
            $this->ToggleSleepTimer(false);
            return 0;
        }
        $remainingTime = intval(round($dividend / $deviceVolume));
        $this->SendDebug(__FUNCTION__, 'Nächste Ausführung in: ' . $remainingTime, 0);
        return $remainingTime;
    }
}