<?php
namespace Sm\LogBundle\Parser;

use Sm\LogBundle\Dto\Telegram;
use Sm\LogBundle\Dto\Channel;
use Sm\LogBundle\Dto\ReadingValue;
use Psr\Log\LoggerInterface;


/**
 * Class Parser
 * @package Sm\LogBundle\Parser
 *
 * /XMX5LGBBFFB231096081
 *
 * 1-3:0.2.8(40)
 * 0-0:1.0.0(140622161029S)
 * 0-0:96.1.1(4530303035303031353538323031323134)
 * 1-0:1.8.1(000037.466*kWh)
 * 1-0:2.8.1(000000.047*kWh)
 * 1-0:1.8.2(000011.423*kWh)
 * 1-0:2.8.2(000000.000*kWh)
 * 0-0:96.14.0(0001)
 * 1-0:1.7.0(00.407*kW)
 * 1-0:2.7.0(00.000*kW)
 * 0-0:17.0.0(999.9*kW)
 * 0-0:96.3.10(1)
 * 0-0:96.7.21(00006)
 * 0-0:96.7.9(00000)
 * 1-0:99.97.0(0)(0-0:96.7.19)
 * 1-0:32.32.0(00001)
 * 1-0:52.32.0(00000)
 * 1-0:72.32.0(00000)
 * 1-0:32.36.0(00000)
 * 1-0:52.36.0(00000)
 * 1-0:72.36.0(00000)
 * 0-0:96.13.1()
 * 0-0:96.13.0()
 * 1-0:31.7.0(000*A)
 * 1-0:51.7.0(003*A)
 * 1-0:71.7.0(001*A)
 * 1-0:21.7.0(00.040*kW)
 * 1-0:41.7.0(00.217*kW)
 * 1-0:61.7.0(00.149*kW)
 * 1-0:22.7.0(00.000*kW)
 * 1-0:42.7.0(00.000*kW)
 * 1-0:62.7.0(00.000*kW)
 * 0-1:24.1.0(003)
 * 0-1:96.1.0(4730303136353631323037373133373134)
 * 0-1:24.2.1(140622160000S)(00003.800*m3)
 * 0-1:24.4.0(1)
 * !A79E
 */
class Parser
{
    /**
     * @var Telegram
     */
    protected $telegram;
    /**
     * @var Channel
     */
    protected $channel;
    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct($logger)
    {
        $this->logger = $logger;
    }

    protected function init()
    {
        $this->telegram = new Telegram();
        $this->channel = [
          1 => new Channel(1),
          2 => new Channel(2),
          3 => new Channel(3),
          4 => new Channel(4)
        ];
    }

    public function parse($data)
    {
        $this->logger->info('Start parsing data to Telegram');
        $this->logger->debug(str_replace(["\n", "\r"], ["\\n", "\\r"], $data));
        $this->init();
        // Loop through data
        $lines = explode("\r\n", $data);
        foreach ($lines as $line) {
            $this->parseLine($line, $data);
        }
        for($i = 1; $i < 5; $i++) {
            if ($this->channel[$i]->getTypeDescription() !== '') {
                $this->telegram->setChannel($this->channel[$i]);
            }
        }
        return $this->telegram;
    }

    protected function parseLine($data, $fullMsg)
    {
        $this->logger->debug('Parse line: |' . $data . '|');
        if (substr($data, 0, 1) === '/') {
            #Header information
            #eg. /KMP5 KA6U001511209910 (Kamstrup Enexis)
            #eg. /ISk5\2ME382-1003 (InkraEmeco Liander)
            #eg. /XMX5XMXABCE000018914 (Landis&Gyr Stedin, Xemex communicatiemodule)
            #eg. /KFM5KAIFA-METER (Kaifa)
            $this->telegram->setMeterSupplier(substr($data, 1, 3));
            $this->telegram->setHeader(substr($data, 1, strlen($data) - 1));
        } elseif (substr($data, 4, 5) === '1.0.0') {
            #P1 Timestamp (DSMR 4)
            #eg. 0-0:1.0.0(101209113020W)
            if (substr($data, 10, 13) != "000101010000W") {
                #Check if meter clock is running
                $timestamp = '20' . substr($data, 10, 12);
                $this->telegram->setTimestamp(new \DateTime($timestamp));
                $this->logger->info("Found timestamp: " . $this->telegram->getTimestamp()->format("YmdHis") . " | Stamp: " . $this->telegram->getTimestamp()->format('U'));
            } else {
                $this->logger->warning("warning: invalid P1-telegram date/time value '".substr($data, 10, 12)."', system date/time used instead: '" . $this->telegram->getTimestamp()->format('YmdHis') . "'");
            }
        } elseif (substr($data, 4, 5) === '0.2.8') {
            #DSMR Version (DSMR V4)
            #eg. 1-3:0.2.8(40)
            $this->telegram->setDsmrVersion(substr($data, 10, strlen($data) - 11));
        } elseif (substr($data, 4, 6) === '96.1.1') {
            #####
            #Channel 0 = E
            #####
            #Equipment identifier (Electricity)
            #eg. 0-0:96.1.1(204B413655303031353131323039393130)
            $this->telegram->setEquipmentId(substr($data, 11, strlen($data) - 12));
        } elseif (substr($data, 4, 5) === '1.8.1') {
            #Meter Reading electricity delivered to client (normal tariff)
            #eg. 1-0:1.8.1(00721.000*kWh) (DSMR 3)
            #eg. 1-0:1.8.1(000038.851*kWh) (DSMR 4)
            $value = substr($data, 10, strlen($data) - 11);
            $tmp = explode('*', $value);
            $this->telegram->setMeterreadingIn1(new ReadingValue(floatval($tmp[0]), $tmp[1]));
        } elseif (substr($data, 4, 5) === '1.8.2') {
            #Meter Reading electricity delivered to client (low tariff)
            #eg. 1-0:1.8.2(00392.000*kWh)
            $value = substr($data, 11, strlen($data) - 12);
            $tmp = explode('*', $value);
            $this->telegram->setMeterreadingIn2(new ReadingValue(floatval($tmp[0]), $tmp[1]));
        } elseif (substr($data, 4, 5) === '2.8.1') {
            #Meter Reading electricity delivered by client (normal tariff)
            #eg. 1-0:2.8.1(00000.000*kWh)
            $value = substr($data, 11, strlen($data) - 12);
            $tmp = explode('*', $value);
            $this->telegram->setMeterreadingOut1(new ReadingValue(floatval($tmp[0]), $tmp[1]));
        } elseif (substr($data, 4, 5) === '2.8.2') {
            #Meter Reading electricity delivered by client (low tariff)
            #eg. 1-0:2.8.2(00000.000*kWh)
            $value = substr($data, 11, strlen($data) - 12);
            $tmp = explode('*', $value);
            $this->telegram->setMeterreadingOut2(new ReadingValue(floatval($tmp[0]), $tmp[1]));
        } elseif (substr($data, 4, 7) === '96.14.0') {
            #Tariff indicator electricity
            #eg. 0-0:96.14.0(0001)
            #alternative 0-0:96.14.0(1)
            $this->telegram->setCurrentTariff(substr($data, 12, strlen($data) - 13));
        } elseif (substr($data, 4, 5) == '1.7.0') {
            #Actual electricity power delivered to client (+P)
            #eg. 1-0:1.7.0(0000.91*kW)
            $value = substr($data, 11, strlen($data) - 12);
            $tmp = explode('*', $value);
            $this->telegram->setCurrentPowerIn(new ReadingValue(floatval($tmp[0]), $tmp[1]));
        } elseif (substr($data, 4, 5) == '2.7.0') {
            #Actual electricity power delivered by client (-P)
            #1-0:2.7.0(0000.00*kW)
            $value = substr($data, 11, strlen($data) - 12);
            $tmp = explode('*', $value);
            $this->telegram->setCurrentPowerOut(new ReadingValue(floatval($tmp[0]), $tmp[1]));
        } elseif (substr($data, 4, 6) == '17.0.0') {
            #Actual threshold Electricity
            #Companion standard, eg Kamstrup, Xemex
            #eg. 0-0:17.0.0(999*A)
            #Iskraemeco
            #eg. 0-0:17.0.0(0999.00*kW)
            $value = substr($data, 11, strlen($data) - 12);
            $tmp = explode('*', $value);
            $this->telegram->setCurrentTreshold(new ReadingValue(floatval($tmp[0]), $tmp[1]));
        } elseif (substr($data, 4, 7) === '96.3.10') {
            #Actual switch position Electricity (in/out/enabled).
            #eg. 0-0:96.3.10(1)
            $this->telegram->setCurrentSwitchPosition(substr($data, 12,1));
        } elseif (substr($data, 4, 7) === '96.7.21') {
            #Number of powerfailures in any phase (DSMR4)
            #eg. 0-0:96.7.21(00004)
            $this->telegram->setPowerFailures(intval(substr($data, 12, strlen($data) - 13)));
        } elseif (substr($data, 4, 6) === '96.7.9') {
            #Number of long powerfailures in any phase (DSMR4)
            #eg. 0-0:96.7.9(00002)
            $this->telegram->setLongPowerFailures(intval(substr($data, 11, strlen($data) - 12)));
        } elseif (substr($data, 4, 7) === '99.97.0') {
            #Powerfailure eventlog (DSMR4)
            #eg. 1-0:99:97.0(2)(0:96.7.19)(101208152415W)(0000000240*s)(101208151004W)(00000000301*s)
            #    1-0:99.97.0(0)(0-0:96.7.19)
            $this->telegram->setLongPowerFailuresLog(substr($data, strrpos($data, '0:96.7.19') + 10, strlen($data)));
        } elseif (substr($data, 4,7) === '32.32.0') {
            #Number of Voltage sags L1 (DSMR4)
            #eg. 1-0:32.32.0(00002)
            $this->telegram->setVoltageSagsL1(intval(substr($data, 12, strlen($data) - 13)));
        } elseif (substr($data, 4, 7) === '52.32.0') {
            #Number of Voltage sags L2 (DSMR4)
            #eg. 1-0:52.32.0(00002)
            $this->telegram->setVoltageSagsL2(intval(substr($data, 12, strlen($data) - 13)));
        } elseif (substr($data, 4, 7) === '72.32.0') {
            #Number of Voltage sags L3 (DSMR4)
            #eg. 1-0:72.32.0(00002)
            $this->telegram->setVoltageSagsL3(intval(substr($data, 12, strlen($data) - 13)));
        } elseif (substr($data, 4, 7) === '32.36.0') {
            #Number of Voltage swells L1 (DSMR4)
            #eg. 1-0:32.36.0(00002)
            $this->telegram->setVoltageSwellsL1(intval(substr($data, 12, strlen($data) - 13)));
        } elseif (substr($data, 4, 7) === '52.36.0') {
            #Number of Voltage swells L2 (DSMR4)
            #eg. 1-0:52.36.0(00002)
            $this->telegram->setVoltageSwellsL2(intval(substr($data, 12, strlen($data) - 13)));
        } elseif (substr($data, 4, 7) === '72.36.0') {
            #Number of Voltage swells L3 (DSMR4)
            #eg. 1-0:72.36.0(00002)
            $this->telegram->setVoltageSwellsL3(intval(substr($data, 12, strlen($data) - 13)));
        } elseif (substr($data, 4, 6) === '31.7.0') {
            #Instantaneous current L1 in A (DSMR4)
            #eg. 1-0:31.7.0.255(001*A)
            $value = substr($data, 11, strlen($data) - 12);
            $tmp = explode('*', $value);
            $this->telegram->setInstantaneousCurrentL1(new ReadingValue(floatval($tmp[0]), $tmp[1]));
        } elseif (substr($data, 4, 6) === '51.7.0') {
            #Instantaneous current L2 in A (DSMR4)
            #eg. 1-0:51.7.0.255(002*A)
            $value = substr($data, 11, strlen($data) - 12);
            $tmp = explode('*', $value);
            $this->telegram->setInstantaneousCurrentL2(new ReadingValue(floatval($tmp[0]), $tmp[1]));
        } elseif (substr($data, 4, 6) === '71.7.0') {
            #Instantaneous current L3 in A (DSMR4)
            #eg. 1-0:71.7.0.255(003*A)
            $value = substr($data, 11, strlen($data) - 12);
            $tmp = explode('*', $value);
            $this->telegram->setInstantaneousCurrentL3(new ReadingValue(floatval($tmp[0]), $tmp[1]));
        } elseif (substr($data, 4, 6) === '21.7.0') {
            #Instantaneous active power L1 (+P) in W (DSMR4)
            #eg 1-0:21.7.0.255(01.111*kW)
            $value = substr($data, 11, strlen($data) - 12);
            $tmp = explode('*', $value);
            $this->telegram->setInstantaneousActivePowerInL1(new ReadingValue(floatval($tmp[0]), $tmp[1]));
        } elseif (substr($data, 4, 6) === '41.7.0') {
            #Instantaneous active power L2 (+P) in W (DSMR4)
            #eg 1-0:41.7.0.255(02.222*kW)
            $value = substr($data, 11, strlen($data) - 12);
            $tmp = explode('*', $value);
            $this->telegram->setInstantaneousActivePowerInL2(new ReadingValue(floatval($tmp[0]), $tmp[1]));
        } elseif (substr($data, 4, 6) === '61.7.0') {
            #Instantaneous active power L3 (+P) in W (DSMR4)
            #eg 1-0:61.7.0.255(03.333*kW)
            $value = substr($data, 11, strlen($data) - 12);
            $tmp = explode('*', $value);
            $this->telegram->setInstantaneousActivePowerInL3(new ReadingValue(floatval($tmp[0]), $tmp[1]));
        } elseif (substr($data, 4, 6) === '22.7.0') {
            #Instantaneous active power L1 (+P) in W  (DSMR4)
            #eg 1-0:22.7.0.255(04.444*kW)
            $value = substr($data, 11, strlen($data) - 12);
            $tmp = explode('*', $value);
            $this->telegram->setInstantaneousActivePowerOutL1(new ReadingValue(floatval($tmp[0]), $tmp[1]));
        } elseif (substr($data, 4, 6) === '42.7.0') {
            #Instantaneous active power L2 (+P) in W  (DSMR4)
            #eg 1-0:42.7.0.255(05.555*kW)
            $value = substr($data, 11, strlen($data) - 12);
            $tmp = explode('*', $value);
            $this->telegram->setInstantaneousActivePowerOutL2(new ReadingValue(floatval($tmp[0]), $tmp[1]));
        } elseif (substr($data, 4, 6) === '62.7.0') {
            #Instantaneous active power L3 (+P) in W (DSMR4)
            #eg 1-0:62.7.0.255(06.666*kW)
            $value = substr($data, 11, strlen($data) - 12);
            $tmp = explode('*', $value);
            $this->telegram->setInstantaneousActivePowerOutL3(new ReadingValue(floatval($tmp[0]), $tmp[1]));
        } elseif (substr($data, 4, 7) === '96.13.1') {
            #Text message code: numeric 8 digits
            #eg. 0-0:96.13.1()
            $this->telegram->setMessageCode(substr($data, 12, strlen($data) - 13));
        } elseif (substr($data, 4, 7) === '96.13.0') {
            #Text message max 1024 characters.
            #eg. 0-0:96.13.0()
            $this->telegram->setMessageText(substr($data, 12, strlen($data) - 13));
        } elseif (substr($data, 4, 6) === '24.1.0') {  #Channels 1/2/3/4: MBus connected meters
            #Device-Type
            #eg. 0-1:24.1.0(3)
            #or 0-1:24.1.0(03) 3=Gas;5=Heat;6=Cooling
            #or 0-1:24.1.0(03) 3/7=Gas;5=Heat;6=Cooling (Standard OBIS: 1-Electricity / 4-HeatCostAllocation / 5-Cooling / 6-Heat / 7-Gas / 8-ColdWater / 9-HotWater)
            $channelId = $this->getChannelId($data);
            $typeId = intval(substr($data, 11, strlen($data) - 12));
            switch ($typeId) {
                case 3:
                case 7:
                    $typeDescription = 'Gas';
                    break;
                case 4:
                    $typeDescription = 'HeatCost';
                    break;
                case 5:
                    $typeDescription = 'Heat';
                    break;
                case 6:
                    $typeDescription = 'Cold';
                    break;
                case 8:
                    $typeDescription = 'Cold water';
                    break;
                case 9:
                    $typeDescription = 'Hot water';
                    break;
                default:
                    $typeDescription = 'Unknown';
            }
            $this->channel[$channelId]->setType($typeId, $typeDescription);
        } elseif (substr($data, 4, 6) === '96.1.0') {
            #Equipment identifier
            #eg. 0-1:96.1.0(3238303039303031303434303132303130)
            $channelId = $this->getChannelId($data);
            $this->channel[$channelId]->setEquipmentId(substr($data, 11, strlen($data) - 12));
        } elseif (substr($data, 4, 6) === '24.3.0') {
            #Last hourly value delivered to client (DSMR < V4)
            #eg. Kamstrup/Iskraemeco:
            #0-1:24.3.0(110403140000)(000008)(60)(1)(0-1:24.2.1)(m3)
            #(00437.631)
            #eg. Companion Standard:
            #0-1:24.3.0(110403140000)(000008)(60)(1)(0-1:24.2.1)(m3)(00437.631)
            $channelId = $this->getChannelId($data);
        } elseif (substr($data, 4, 6) === '24.2.1') {
            #Last hourly value delivered to client (DSMR v4)
            #eg. 0-1:24.2.1(101209110000W)(12785.123*m3)
            $channelId = $this->getChannelId($data);
            $meterData = substr($data, 26, strlen($data) - 27);
            $tmp = explode('*', $meterData);
            $meterReading = floatval($tmp[0]);
            $unit = $tmp[1];
            $timestamp = '20' . substr($data, 11, 12);
            $this->channel[$channelId]->setReadingValue(new ReadingValue(floatval($meterReading), $unit), new \DateTime($timestamp));
        } elseif (substr($data, 4, 6) === '24.4.0') {
            #Valve position (on/off/released)
            #eg. 0-1:24.4.0()
            #eg. 0-1:24.4.0(1)
            #Valveposition defaults to '1'(=Open) if invalid value
            $channelId = $this->getChannelId($data);
            $this->channel[$channelId]->setValvePosition(substr($data, 11, strlen($data) - 12));
        } elseif (substr($data, 0, 1) == '' || substr($data, 0, 1) == ' ' || ord($data) == 13) {
            // Empty line
        } elseif (substr($data, 0, 1) === '!') {
            #in DSMR 4 telegrams there might be a checksum following the "!".
            #eg. !141B
            #CRC16 value calculated over the preceding characters in the data message (from “/” to “!” using the polynomial: x16+x15+x2+1).
            #the checksum is discarded
            $crc = substr($data, 1, strlen($data) -1);
            $this->telegram->setCrc($crc);
            $this->telegram->setComplete();
        } else {
            $this->logger->warning("Unknown Line: " . $data);
        }
    }

    protected function getChannelId($data)
    {
        $channelId = intval(substr($data, 2,1));
        return $channelId;
    }
}
