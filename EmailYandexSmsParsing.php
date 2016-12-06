<?php
/**
 * Class NotifyYandexSmsParsing
 * Тестовая задача №1
 * Обработчик уведомления администратора/модератора при ошибках парсинга СМС от Яндекс.Деньги
 *
 * Реализована отправка сообщения на емейл,
 * в зависимости от архитектуры приложения, легко дополняется записью в базу - для админки и пр.
 */

namespace Funpay;


use SplSubject;

class NotifyYandexSmsParsing implements \SplObserver
{
    // Адреса на которые отправляются уведомления
    protected $emails = ['admin-notify@fanpay.ru'];

    /**
     * NotifyYandexSmsParsing constructor.
     */
    public function __construct()
    {
        // TODO: реализовать инициализацию адреса/ов получения уведомлений из настроек приложения
        $this->emails = ['admin-notify@fanpay.ru'];
    }

    /**
     * Отправка сообщения
     *
     * @param SplSubject $subject
     */
    public function update(SplSubject $subject)
    {
        // Получаем данные из парсера
        $source = $subject->extractData();

        if (false === $source['data']) {
            /* если возникла ошибка при парсинге
             * формируем уведомление
             *
             * Два вида уведомлений:
             * 1. Получено пустое СМС (ошибка в получении)
             * 2. Изменен формат сообщения, ошибка при парсинге
             *
             */
            if (empty($source['message'])) {
                $logSubject = 'Подтверждающее СМС от Яндекса не пришло! Требуется вмешательство';
                $logMessage = "При парсинге СМС от Яндекс.Деньги возникла ошибка. \n 
                            Пришло пустое СМС \n " .
                    date("Y-m-d H:i:s");
            } else {
                $logSubject = 'Яндекс изменил формат СМС! Требуется вмешательство';
                $logMessage = "При парсинге СМС от Яндекс.Деньги возникла ошибка. \n 
                            Скорее всего кардинально изменился формат СМС и требуется вмешательство! \n " .
                    date("Y-m-d H:i:s") .
                    " \t Полученое СМС от Яндекса: \n" .
                    $source['message'];
            }
            mail(implode(', ', $this->emails), $logSubject, $logMessage);

            // TODO: При необходимости в этом месте добавить запись в базу для админки и пр.
        }
    }
}