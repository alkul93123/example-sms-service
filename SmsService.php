<?php

namespace App\Services\Sms;

use App\Services\Sms\Providers\SmsAeroProvider;
use Closure;
use App\Services\Sms\Core\BaseSanitizer;
use App\Services\Sms\Exceptions\ValidateNumberException;
use App\Services\Sms\Interfaces\ProviderInterface;
use App\Services\Sms\Interfaces\SanitizerInterface;
use App\Services\Sms\Interfaces\SmsServiceInterface;
use App\Models\Sms\SmsMessage;
use Exception;
use Illuminate\Support\Facades\App;
use NunoMaduro\Collision\Provider;

/**
 * Class SmsService для отправки смс сообщений
 * @package App\Services\Sms
 *
 * Как пользоваться:
 * ```php
 *  // Простое использование
 *  $service = new SmsService();
 *  $service->setNumbers(['9045344321', '+79045342314'])->setMessage('Тестовое сообщение')->send();
 *
 *  // Или
 *  (new SmsService(['9045344321', '+79045342314'], 'Тестовое сообщение'))->send();
 *
 *  // Или
 *  $service = (new SmsService)->push(['9045344321', '+79045342314'], 'Тестовое сообщение');
 *
 *  // Дополнительные возможности:
 *  $service = new SmsService(['9045344321', '+79045342314'], 'Тестовое сообщение');
 *
 *  // Каждый номер очищается от лишних символов, для этого используется санитайзер, можно внедрить кастомный санитайзер
 *  // !!! Санитайзер должен реализовать интерфейс App\Services\Sms\Interfaces\SanitizerInterface
 *  $service->setSanitizer(new CustomSanitizer);
 *
 *  // Можно добавить дополнительные номера в процессе формирования объекта
 *  $service->addNumber('79635441234');
 *
 *  // Если нужно преобразовать сообщение перед отправкой, можно добавить декоратор в виде замыкания или объекта Closure
 *  $service->setMessageDecorator(fn($x) => preg_replace('/Маша/', 'Миша', $x));
 *
 *  // Можно использовать свой провайдер сообщений
 *  // Провайдер должен реализовать интерфейс App\Services\Sms\Interfaces\ProviderInterface
 *  $service->setProvider(new CustomProvider); // или через конструктор new SmsService($numbers, $message, new CustomProvider)
 *
 *  $service->send();
 *
 * ```
 *
 *
 * Реальные сообщения отправляются только в продакшн окружении, но независимо от окружения все сообщения записываются в
 * таблицу {{sms_messages}}.
 * TODO: подумать на счет удаления сообщений по проходу какого-то времени (Напр. месяца) или по достижению определенного кол-ва (напр > 1000).
 */
class SmsService implements SmsServiceInterface
{
    protected array              $numbers;
    protected string             $message;
    protected Closure            $messageDecorator;
    protected SanitizerInterface $sanitizer;
    protected ?ProviderInterface $provider;

    /**
     * SmsService constructor.
     * @param array $numbers
     * @param string $message
     * @param ProviderInterface|null $provider
     * @throws ValidateNumberException
     * @throws Exception
     */
    public function __construct(array $numbers = null, string $message = null, ProviderInterface $provider = null)
    {
        $this->message = $this->setMessage($message);
        foreach ($numbers as $number) {
            $this->addNumber($number);
        }

        $this->sanitizer        = new BaseSanitizer;
        $this->messageDecorator = fn($x) => $x;
        $this->provider         = $provider ?? null;

        if (is_null($this->provider)) {
            throw new Exception("Sms provider don't configured! Make sure that you inject this via DI 
                or send it as third argument via SmsService constructor");
        }
    }

    /**
     * @param array $numbers
     * @param string $message
     * @throws ValidateNumberException
     */
    public function push(array $numbers, string $message)
    {
        $this->message = $this->setMessage($message);
        foreach ($numbers as $number) {
            $this->addNumber($number);
        }

        $this->send();
    }

    public function setMessage(string $message): SmsServiceInterface
    {
        $this->message = $this->messageDecorator->call($message);
        return $this;
    }

    public function setMessageDecorator(Closure $fn): SmsServiceInterface
    {
        $this->messageDecorator = $fn;
        return $this;
    }

    /**
     * @param array $numbers
     * @return SmsServiceInterface
     * @throws ValidateNumberException
     */
    public function setNumbers(array $numbers): SmsServiceInterface
    {
        foreach ($numbers as $number) {
            $this->addNumber($number);
        }

        return $this;
    }

    /**
     * @param int $number
     * @return SmsServiceInterface
     * @throws ValidateNumberException
     */
    public function addNumber(int $number): SmsServiceInterface
    {
        $clearNumber = $this->sanitizer->sanitizeNumber($number);

        /** TODO: Добавить внедрение валидатора, т.к. в других странах может быть другое кол-во символов */
        if (!in_array(mb_strlen($clearNumber), [10, 11])) {
            throw new ValidateNumberException("Phone number must have 10 characters. Error with number {$clearNumber}");
        }

        if (!in_array($clearNumber, $this->numbers)) {
            $this->numbers[] = $clearNumber;
        }

        return $this;
    }

    public function setSanitizer(SanitizerInterface $sanitizer): SmsServiceInterface
    {
        $this->sanitizer = $sanitizer;
        return $this;
    }

    public function setProvider(ProviderInterface $provider): SmsServiceInterface
    {
        $this->provider = $provider;
        return $this;
    }

    /**
     * @param int $number
     * @param string $message
     * @param ProviderInterface|null $provider
     * @return void
     * @throws ValidateNumberException
     */
    public function quick(int $number, string $message, ProviderInterface $provider = null)
    {
        $provider = $provider ?? new SmsAeroProvider(
            config('services.smsaero.login'),
            config('services.smsaero.login'),
            config('services.smsaero.sign')
        );

        $self = new self([$number], $message, $provider);

        return $self->send();
    }

    /**
     * TODO: Подумать над тем, чтобы реализовать интерфейс для хранилища сообщений, чтобы можно было хранить напр. в redis
     */
    protected function send()
    {
        $messageIds = [];
        foreach ($this->numbers as $number) {
            $message = new SmsMessage([
                'number' => $number,
                'message' => $this->message,
                'is_real_send' => false
            ]);

            $message->save();
            $messageIds[] = $message->id;
        }

        if (App::environment('production')) {
            $this->provider->setNumbers($this->numbers)->setMessage($this->message)->send();

            /** @noinspection PhpUndefinedMethodInspection */
            SmsMessage::whereIn(['id' => $messageIds])->update(['is_real_send' => true]);
        }

    }
}