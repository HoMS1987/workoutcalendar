<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Support;

use OCP\Notification\IAction;
use OCP\Notification\INotification;

/** Simple test implementation of INotification that merely records the values set. */
final class RecordingNotification implements INotification
{
    private string $app = '';
    private string $user = '';
    private \DateTime $dateTime;
    private string $objectType = '';
    private string $objectId = '';
    private string $subject = '';
    /** @var array<string, mixed> */
    private array $subjectParameters = [];
    private string $parsedSubject = '';
    private string $richSubject = '';
    /** @var array<string, mixed> */
    private array $richSubjectParameters = [];
    private string $message = '';
    /** @var array<string, mixed> */
    private array $messageParameters = [];
    private string $parsedMessage = '';
    private string $richMessage = '';
    /** @var array<string, mixed> */
    private array $richMessageParameters = [];
    private string $link = '';
    private string $icon = '';
    private bool $priority = false;

    public function __construct()
    {
        $this->dateTime = new \DateTime('@0');
    }

    public function setApp(string $app): INotification
    {
        $this->app = $app;

        return $this;
    }

    public function getApp(): string
    {
        return $this->app;
    }

    public function setUser(string $user): INotification
    {
        $this->user = $user;

        return $this;
    }

    public function getUser(): string
    {
        return $this->user;
    }

    public function setDateTime(\DateTime $dateTime): INotification
    {
        $this->dateTime = $dateTime;

        return $this;
    }

    public function getDateTime(): \DateTime
    {
        return $this->dateTime;
    }

    public function setObject(string $type, string $id): INotification
    {
        $this->objectType = $type;
        $this->objectId = $id;

        return $this;
    }

    public function getObjectType(): string
    {
        return $this->objectType;
    }

    public function getObjectId(): string
    {
        return $this->objectId;
    }

    public function setSubject(string $subject, array $parameters = []): INotification
    {
        $this->subject = $subject;
        $this->subjectParameters = $parameters;

        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getSubjectParameters(): array
    {
        return $this->subjectParameters;
    }

    public function setParsedSubject(string $subject): INotification
    {
        $this->parsedSubject = $subject;

        return $this;
    }

    public function getParsedSubject(): string
    {
        return $this->parsedSubject;
    }

    public function setRichSubject(string $subject, array $parameters = []): INotification
    {
        $this->richSubject = $subject;
        $this->richSubjectParameters = $parameters;

        return $this;
    }

    public function getRichSubject(): string
    {
        return $this->richSubject;
    }

    public function getRichSubjectParameters(): array
    {
        return $this->richSubjectParameters;
    }

    public function setMessage(string $message, array $parameters = []): INotification
    {
        $this->message = $message;
        $this->messageParameters = $parameters;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getMessageParameters(): array
    {
        return $this->messageParameters;
    }

    public function setParsedMessage(string $message): INotification
    {
        $this->parsedMessage = $message;

        return $this;
    }

    public function getParsedMessage(): string
    {
        return $this->parsedMessage;
    }

    public function setRichMessage(string $message, array $parameters = []): INotification
    {
        $this->richMessage = $message;
        $this->richMessageParameters = $parameters;

        return $this;
    }

    public function getRichMessage(): string
    {
        return $this->richMessage;
    }

    public function getRichMessageParameters(): array
    {
        return $this->richMessageParameters;
    }

    public function setLink(string $link): INotification
    {
        $this->link = $link;

        return $this;
    }

    public function getLink(): string
    {
        return $this->link;
    }

    public function setIcon(string $icon): INotification
    {
        $this->icon = $icon;

        return $this;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function setPriorityNotification(bool $priorityNotification): INotification
    {
        $this->priority = $priorityNotification;

        return $this;
    }

    public function isPriorityNotification(): bool
    {
        return $this->priority;
    }

    public function createAction(): IAction
    {
        throw new \LogicException('Aktionen werden in den Tests nicht benötigt.');
    }

    public function addAction(IAction $action): INotification
    {
        throw new \LogicException('Aktionen werden in den Tests nicht benötigt.');
    }

    public function getActions(): array
    {
        return [];
    }

    public function addParsedAction(IAction $action): INotification
    {
        throw new \LogicException('Aktionen werden in den Tests nicht benötigt.');
    }

    public function getParsedActions(): array
    {
        return [];
    }

    public function isValid(): bool
    {
        return true;
    }

    public function isValidParsed(): bool
    {
        return true;
    }
}
