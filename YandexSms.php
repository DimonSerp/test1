<?php
/**
 * Class YandexSms
 * Тестовая задача №1
 * Парсинг СМС от Яндекс.Деньги
 */

namespace Funpay;


use \SplObserver;

class YandexSms implements \SplSubject
{
    // Хранилище обработчиков событий
    protected $observers;
    // Результаты парсинга
    protected $data = false;
    // Текст сообщения для парсинга
    protected $message = '';

    public function __construct() {
        // Инициализация хранилища обработчиков
        $this->observers = new \SplObjectStorage();
        // Инициализация обработчиков
        $this->attach(new LogYandexSmsParsing(LogYandexSmsParsing::DESTINATION_FILES));
        $this->attach(new NotifyYandexSmsParsing());
    }

    /**
     * @param string $message - Исходный текст СМС
     * @return boolean|array - Результат в формате ['code' => value, 'amount' => value, 'receiver' => value] или false в случае ошибок парсинга
     */
    public function parseSms($message)
    {
        // сохраняем значение текста СМС для передачи в обработчики
        $this->message = $message;
        $this->data = false;

        $code = '';
        $amount = 0;
        $receiver = '';

        // если сообщение не пустое
        if (!empty($message)) {
            // поиск кода подтверждения, 4 цифровых символа, отделенных с двух сторон от текста любым не цифровым символом, либо в конце текста
            if (preg_match("~\s(\d{4})(?:[^\d]+|$)~isu", $message, $code)) {
                $code = $code[1];
            }
            // поиск суммы, цифры с дробной частью или без (разделитель . или ,) после которых стоит признак валюты "р."
            if (preg_match("~\s(\d+(?:,|.)\d*)р\.~isu", $message, $amount)) {
                $amount = floatval(str_replace(',', '.', $amount[1]));
            }
            // поиск номера кошелька получателя, от 11 до 26 цифровых символа, отделенных с двух сторон от текста любым не цифровым символом, либо в конце текста
            if (preg_match("~\s(\d{11,26})[^\d]*~isu", $message, $receiver)) {
                $receiver = $receiver[1];
            }

            // Проверяем результаты парсинга, и запоминаем если все ок
            if (!empty($code) && $this->checkAmount($amount) && self::checkYandexPurse($receiver)) {
                $this->data = [
                    'code' => $code,
                    'amount' => $amount,
                    'receiver' => $receiver
                ];
            }
        }
        // Запускаем обработчики - логирование, уведомление
        $this->notify();

        return $this->data;
    }

    /**
     * Проверка суммы
     *
     * @param double $amount
     * @return bool
     */
    private function checkAmount($amount)
    {
        return (!empty($amount) && is_double($amount));
    }

    /**
     * Выполняет проверку номера счета в системе Яндекс.Деньги по заданному алгоритму проверки действительности строки, представляющей номер счета.
     * @param string $purseNum Номер счета Яндекс.Денег
     * @return bool Возвращает true если номер счета корректен, иначе false
     * @link https://github.com/Olegf13/check_yandex_purse
     * @author Oleg Fedorov
     * Алгоритм проверки действительности строки (под разбором строки будем понимать определение X, Y и Z по заданному E=NXYZ) состоит из 7 шагов, шаги описаны комментариями в коде.
     * Длина существующих на данный момент номеров счетов варьируется от 11 до 16 цифр.
     */
    public static function checkYandexPurse($purseNum){
        // 1. Если строка E пуста или содержит символы, отличные от цифр, то разбор невозможен и строка недействительна.
        if(!$purseNum || !is_numeric($purseNum)){
            return false;
        }
        // 2. Если первая цифра E равна 0, то разбор невозможен и строка недействительна, в противном случае N = первая цифра E.
        $purseN = (string)$purseNum[0];
        if((int)$purseN === 0){
            return false;
        }
        // 3. Если длина E меньше N+4, то разбор невозможен и строка недействительна.
        $purseLength = strlen($purseNum);
        if($purseLength < ((int)$purseN + 4)){
            return false;
        }
        // 4. Если две последние цифры строки E равны "00", то строка недействительна.
        $purseZ = (string)mb_substr($purseNum, ($purseLength - 2), 2);
        if($purseZ === '00'){
            return false;
        }
        // 5. Если выполнены условия 1-4, то строка E может быть разобрана и: X = N цифр E, начиная со второй; Z = две последних цифры E; Y = оставшиеся цифры E.
        $purseX = (string)mb_substr($purseNum, 1, $purseN);
        $purseY = (string)mb_substr($purseNum, (strlen($purseX) + 1), -2);
        // 6. Если длина Y больше 20, то строка недействительна.
        if(strlen($purseY) > 20){
            return false;
        }
        // 7. Если AccountNumberRedundancy (X,Y) не равно Z, то строка недействительна.
        $checkSum = (string)self::accountNumberRedundancy($purseX, $purseY);
        if($checkSum !== $purseZ){
            return false;
        }
        return true;
    }

    /**
     * Реализует алгоритм вычисления контрольной суммы (последние 2 цифры в номере счета) с помощью предшествующей части номера счета,
     * позволяет выявлять случайные ошибки при вводе номера счета.
     * @param string $processingCode Код процессингового центра в Системе (X)
     * @param string $accountCode Номер счета в процессинговом центре Системы (Y)
     * @return string Контрольная сумма (Z)
     */
    public static function accountNumberRedundancy($processingCode, $accountCode){
        /**
         * Результат выражения (13^i) mod 99, где i = 0..32 (последовательность степеней)
         * Т.е. остаток от целочисленного деления на 99 степеней числа 13, можно сгенерировать след. образом:
         * $redundancyFactors[0] = 1;
         * for($i = 1; $i < 32; $i++){
         *  $redundancyFactors[$i] = ($redundancyFactors[$i-1] * 13) % 99;
         * }
         */
        $redundancyFactors = array(1, 13, 70, 19, 49, 43, 64, 40, 25, 28, 67, 79, 37, 85, 16, 10, 31, 7, 91, 94, 34, 46, 4, 52, 82, 76, 97, 73, 58, 61, 1, 13);
        // При вычислении функции accountNumberRedundancy строка X записывается как последовательность 10 десятичных цифр
        $processingCode = strrev($processingCode);
        $purseXSequence = array();
        for($i = 0; $i < 10; $i++){
            $purseXSequence[$i] = (isset($processingCode[$i])) ? $processingCode[$i] : 0;
        }
        // При вычислении функции accountNumberRedundancy строка Y записывается как последовательность 20 десятичных цифр
        $accountCode = strrev($accountCode);
        $purseYSequence = array();
        for($i = 0; $i < 20; $i++){
            $purseYSequence[$i] = (isset($accountCode[$i])) ? $accountCode[$i] : 0;
        }
        // Переменная для хранения результата
        $result = 0;
        // Показатель степени
        $exponent = 2;
        /**
         * Функция вычисляется следующим образом (разбита на 2 цикла): вычисляется сумма последовательности:
         * ([k * (13^0) mod 99 + k * (13^1) mod 99 + ... + k * (13^i) mod 99])
         */
        // для i = 0..19 текущий коэффициент $k равен соответствующей цифре последовательности $purseYSequence, но нули заменяются на 10
        for($i = 0; $i < 20; $i++){
            $k = ($purseYSequence[$i]) ? $purseYSequence[$i] : 10;
            $result += $k * $redundancyFactors[$exponent];
            $exponent++;
        }
        // для i = 20..29 текущий коэффициент $k равен соответствующей цифре последовательности $purseXSequence, но нули заменяются на 10
        for($i = 0; $i < 10; $i++){
            $k = ($purseXSequence[$i]) ? $purseXSequence[$i] : 10;
            $result += $k * $redundancyFactors[$exponent];
            $exponent++;
        }
        // с полученной суммой выполняем: mod 99 + 1
        $result = $result % 99 + 1;
        // Если в результате число получилось меньше 10, то дописываем 0 в
        return (string)($result < 10) ? '0' . $result : $result;
    }

    /**
     * Реализация присоединения обработчика
     *
     * @param SplObserver $observer
     */
    public function attach(SplObserver $observer)
    {
        $this->observers->attach($observer);
    }

    /**
     * Реализация отключения обработчика
     *
     * @param SplObserver $observer
     */
    public function detach(SplObserver $observer)
    {
        $this->observers->detach($observer);
    }

    /**
     * Запуск обработки событий
     */
    public function notify()
    {
        foreach ($this->observers as $observer) {
            $observer->update($this);
        }
    }

    /**
     * Извлечение данных для обсерверов
     *
     * @return array
     */
    public function extractData()
    {
        return [
            'data' => $this->data,
            'message' => $this->message
        ];
    }
}