<?php
/**
 * Class LogYandexSmsParsing
 * Тестовая задача №1
 * Обработчик записи логов парсинга СМС от Яндекс.Деньги
 *
 * для примера реализована запись логов в файл
 */

namespace Funpay;


use SplSubject;

class LogYandexSmsParsing implements \SplObserver
{
    // Константы для определения хранения логов
    const DESTINATION_FILES = 'files';
    const DESTINATION_DB = 'db';

    // Имя файла (по-умолчанию) логов успешного парсинга Смс
    protected $logSuccessFileName = 'log_success_parsing_sms.log';
    // Имя файла (по-умолчанию) логов ошибок при парсинге Смс
    protected $logErrorFileName = 'log_error_parsing_sms.log';
    //
    protected $destination;

    /**
     * LogYandexSmsParsing constructor.
     * @param string $destination - Где будем хранить логи, по умолчанию - в файлах
     */
    public function __construct($destination = self::DESTINATION_FILES)
    {
        if (in_array($destination, [
            self::DESTINATION_FILES,
            self::DESTINATION_DB
        ])) {
            $this->destination = $destination;
        } else {
            $this->destination = self::DESTINATION_FILES;
        }
        // TODO: В соответствии со структурой приложения реализовать присвоение правильных путей для файлов или подключения к базе для хранения логов
    }

    /**
     * Обработчик записи логов парсинга СМС от Яндекс.Деньги
     *
     * @param SplSubject $subject - Источник вызова
     */
    public function update(SplSubject $subject)
    {
        // Получаем данные из парсера, массив формата ['data' => $data, 'message' => $message]
        $source = $subject->extractData();

        switch ($this->destination)
        {
            case self::DESTINATION_FILES:
                if (false === $source['data']) {
                    /* логирование ошибки парсинга
                     * формат файла:
                     * 2016-12-05 13:24:05   текст СМС
                     */
                    $logFile = $this->logErrorFileName;
                    $logMessage = date("Y-m-d H:i:s") . " \t " . $source['message'] . PHP_EOL;
                } else {
                    /* логирование успешного парсинга
                     * формат файла:
                     * 2016-12-05 13:24:05   JSON данных
                     */
                    $logFile = $this->logSuccessFileName;
                    $logMessage = date("Y-m-d H:i:s") . " \t " . json_encode($source['data']) . PHP_EOL;
                }
                // запись в файл
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                break;
            case self::DESTINATION_DB:
                // TODO: Реализовать хранения логов в Базе данных при необходимости.
                break;
        }
    }
}