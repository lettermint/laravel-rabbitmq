<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Filament\Pages;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\Width;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Actions\Dlq\InspectDlqMessages;
use Lettermint\RabbitMQ\Actions\Dlq\PurgeDlqMessages;
use Lettermint\RabbitMQ\Actions\Dlq\ReplayDlqMessages;
use Lettermint\RabbitMQ\Monitoring\QueueMetrics;
use Lettermint\RabbitMQ\Support\ExceptionReporter;
use Lettermint\RabbitMQ\Topology\TopologyRegistry;
use Throwable;

final class RabbitMQDeadLetters extends Page implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

    protected static ?string $title = 'RabbitMQ dead letters';

    protected static ?string $navigationLabel = 'RabbitMQ failures';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected string $view = 'rabbitmq::filament.pages.dead-letters';

    public string $queue = '';

    public static function canAccess(): bool
    {
        $ability = config('rabbitmq.filament.gate', 'viewRabbitMQDeadLetters');

        return is_string($ability)
            && trim($ability) !== ''
            && Gate::allows($ability);
    }

    public function mount(): void
    {
        $requested = request()->string('queue')->toString();
        $this->queue = in_array($requested, $this->deadLetterQueues(), true)
            ? $requested
            : '';
    }

    public function table(Table $table): Table
    {
        return $this->queue === ''
            ? $this->queueTable($table)
            : $this->messageTable($table);
    }

    protected function queueTable(Table $table): Table
    {
        return $table
            ->heading('Dead-letter queues')
            ->description('Select a queue to view and manage its failed messages.')
            ->records(fn (): array => $this->queueRecords())
            ->columns([
                TextColumn::make('queue')
                    ->label('Queue')
                    ->description(fn (array $record): string => $record['physical_queue']),
                TextColumn::make('messages')
                    ->label('Messages')
                    ->numeric()
                    ->badge()
                    ->color(fn (?int $state): string => match (true) {
                        $state === null => 'gray',
                        $state > 0 => 'danger',
                        default => 'success',
                    })
                    ->placeholder('Unavailable'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Available' ? 'success' : 'danger')
                    ->description(fn (array $record): ?string => $record['error']),
            ])
            ->recordAction('open')
            ->recordActions([
                Action::make('open')
                    ->label('Open queue')
                    ->icon('heroicon-m-arrow-right')
                    ->url(fn (array $record): string => self::getUrl(['queue' => $record['queue']])),
            ])
            ->emptyStateHeading('No dead-letter queues are registered')
            ->emptyStateDescription('Add a dead-letter queue to the RabbitMQ topology before you use this page.')
            ->paginated(false);
    }

    protected function messageTable(Table $table): Table
    {
        return $table
            ->heading("Messages in {$this->queue}")
            ->description('The table shows up to 100 messages. Use Inspect to load the full exception and payload for one message.')
            ->records(fn (): array => $this->messageRecords())
            ->columns([
                TextColumn::make('id')->label('Job ID')->copyable(),
                TextColumn::make('job_class')->label('Job')->formatStateUsing(fn (string $state): string => class_basename($state)),
                TextColumn::make('attempts')->numeric(),
                TextColumn::make('failed_at')->dateTime()->placeholder('Unknown'),
                TextColumn::make('reason')->badge(),
            ])
            ->recordActions([
                Action::make('inspect')
                    ->modalHeading('Dead-letter details')
                    ->modalWidth(Width::SevenExtraLarge)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->fillForm(fn (array $record): array => $this->messageDetails($record))
                    ->schema([
                        Section::make('Message')
                            ->schema([
                                TextEntry::make('logical_queue')->label('Queue'),
                                TextEntry::make('id')->label('Job ID')->copyable(),
                                TextEntry::make('job_class')->label('Job')->copyable(),
                                TextEntry::make('attempts'),
                                TextEntry::make('failed_at')->label('Failed at')->placeholder('Unknown'),
                                TextEntry::make('reason')->badge(),
                            ])
                            ->columns(2),
                        Tabs::make('Message content')
                            ->tabs([
                                Tab::make('Exception')
                                    ->icon('heroicon-m-exclamation-triangle')
                                    ->schema([
                                        Textarea::make('exception')
                                            ->label('Exception and stack trace')
                                            ->rows(16)
                                            ->readOnly()
                                            ->placeholder('No exception details are available.')
                                            ->extraInputAttributes([
                                                'class' => 'font-mono text-xs',
                                                'style' => 'max-height: 28rem; overflow-y: auto;',
                                            ]),
                                    ]),
                                Tab::make('Payload')
                                    ->icon('heroicon-m-code-bracket')
                                    ->schema([
                                        Textarea::make('payload')
                                            ->label('Message payload')
                                            ->rows(20)
                                            ->readOnly()
                                            ->extraInputAttributes([
                                                'class' => 'font-mono text-xs',
                                                'style' => 'max-height: 32rem; overflow-y: auto;',
                                            ]),
                                    ]),
                            ]),
                    ]),
                Action::make('retry')
                    ->requiresConfirmation()
                    ->action(fn (array $record) => $this->retry($record['id'])),
                Action::make('forget')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (array $record) => $this->forget($record['id'])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('retry')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $this->retryMany($records)),
                    BulkAction::make('forget')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $this->forgetMany($records)),
                ]),
            ])
            ->paginated(false);
    }

    /** @return list<array<string, mixed>> */
    protected function queueRecords(): array
    {
        $metrics = app(QueueMetrics::class);
        $records = [];

        foreach (app(TopologyRegistry::class)->queues() as $definition) {
            if (! $definition->deadLetterEnabled) {
                continue;
            }

            $stats = $metrics->getPhysicalQueueStats($definition->deadLetterQueue);
            $records[] = [
                'key' => $definition->logicalName,
                'queue' => $definition->logicalName,
                'physical_queue' => $definition->deadLetterQueue,
                'messages' => $stats['messages'],
                'status' => $stats['connected'] ? 'Available' : 'Unavailable',
                'error' => $stats['error'],
            ];
        }

        usort($records, static function (array $first, array $second): int {
            if ($first['messages'] === null) {
                return $second['messages'] === null
                    ? $first['queue'] <=> $second['queue']
                    : 1;
            }

            if ($second['messages'] === null) {
                return -1;
            }

            return ($second['messages'] <=> $first['messages'])
                ?: ($first['queue'] <=> $second['queue']);
        });

        return $records;
    }

    /** @return list<array<string, mixed>> */
    protected function messageRecords(): array
    {
        if ($this->queue === '') {
            return [];
        }

        $result = app(InspectDlqMessages::class)($this->queue, limit: 100);

        return array_map(
            fn ($message): array => [
                'key' => $message->id,
                'id' => $message->id,
                'job_class' => $message->jobClass,
                'attempts' => $message->attempts,
                'failed_at' => $message->failedAt,
                'reason' => $message->reason,
            ],
            $result->messages,
        );
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function messageDetails(array $record): array
    {
        $id = (string) $record['id'];
        $result = app(InspectDlqMessages::class)($this->queue, messageId: $id);
        $message = $result->messages[0] ?? null;

        if ($message === null) {
            return [
                ...$record,
                'logical_queue' => $this->queue,
                'failed_at' => $this->formatDateTime($record['failed_at'] ?? null),
                'reason' => 'not found',
                'exception' => 'The message is no longer in the dead-letter queue. It may have been retried or removed.',
                'payload' => 'The payload is no longer available.',
            ];
        }

        $provider = app()->bound('queue.failer') ? app('queue.failer') : null;
        $failed = $this->findFailureDetails($provider, $message->id);
        $failedException = is_object($failed) && isset($failed->exception)
            ? (string) $failed->exception
            : null;

        return [
            'logical_queue' => $this->queue,
            'id' => $message->id,
            'job_class' => $message->jobClass,
            'attempts' => $message->attempts,
            'failed_at' => $this->formatDateTime($message->failedAt),
            'reason' => $message->reason,
            'exception' => $failedException ?? $this->formatStructuredValue($message->exception),
            'payload' => $this->formatStructuredValue($message->payload, $message->rawBody),
        ];
    }

    protected function getHeaderActions(): array
    {
        if ($this->queue === '') {
            return [];
        }

        return [
            Action::make('allQueues')
                ->label('All queues')
                ->icon('heroicon-m-arrow-left')
                ->url(self::getUrl()),
            Action::make('selectQueue')
                ->label('Select queue')
                ->fillForm(['queue' => $this->queue])
                ->schema([
                    Select::make('queue')
                        ->options(array_combine($this->deadLetterQueues(), $this->deadLetterQueues()))
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->redirect(static::getUrl(['queue' => (string) $data['queue']]), navigate: true);
                }),
        ];
    }

    protected function formatDateTime(mixed $value): ?string
    {
        if (is_object($value) && method_exists($value, 'toDateTimeString')) {
            return $value->toDateTimeString();
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function formatStructuredValue(mixed $value, ?string $fallback = null): ?string
    {
        if ($value === null) {
            return $fallback;
        }

        if (is_string($value)) {
            return $value;
        }

        $encoded = json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return is_string($encoded) ? $encoded : $fallback;
    }

    protected function retry(string $id): void
    {
        $result = app(ReplayDlqMessages::class)($this->queue, messageId: $id);

        if ($result->replayedCount !== 1) {
            Notification::make()->danger()->title('The job was not retried')->send();

            return;
        }

        $this->forgetFailureDetails($id);
        $this->auditOperator('retry', $id);
        $this->resetTable();
        Notification::make()->success()->title('The job was sent to its queue')->send();
    }

    protected function forget(string $id): void
    {
        $result = app(PurgeDlqMessages::class)($this->queue, messageId: $id);

        if ($result->purgedCount !== 1) {
            Notification::make()->danger()->title('The job was not removed')->send();

            return;
        }

        $this->forgetFailureDetails($id);
        $this->auditOperator('forget', $id);
        $this->resetTable();
        Notification::make()->success()->title('The job was removed')->send();
    }

    protected function retryMany(Collection $records): void
    {
        foreach ($records as $record) {
            $this->retry((string) $record['id']);
        }
    }

    protected function forgetMany(Collection $records): void
    {
        foreach ($records as $record) {
            $this->forget((string) $record['id']);
        }
    }

    protected function forgetFailureDetails(string $id): void
    {
        $provider = app()->bound('queue.failer') ? app('queue.failer') : null;

        if (! $provider instanceof FailedJobProviderInterface) {
            return;
        }

        try {
            $provider->forget($id);
        } catch (Throwable $exception) {
            ExceptionReporter::report($exception);
            Log::warning('RabbitMQ DLQ action could not remove optional failed-job details', [
                'job_id' => $id,
                'exception_class' => $exception::class,
            ]);
        }
    }

    protected function findFailureDetails(mixed $provider, string $id): mixed
    {
        if (! $provider instanceof FailedJobProviderInterface) {
            return null;
        }

        try {
            return $provider->find($id);
        } catch (Throwable $exception) {
            ExceptionReporter::report($exception);
            Log::warning('RabbitMQ DLQ page could not read optional failed-job details', [
                'job_id' => $id,
                'exception_class' => $exception::class,
            ]);

            return null;
        }
    }

    /** @return list<string> */
    protected function deadLetterQueues(): array
    {
        return app(TopologyRegistry::class)->deadLetterQueueNames();
    }

    protected function auditOperator(string $action, string $jobId): void
    {
        Log::notice('RabbitMQ DLQ operator action', [
            'action' => $action,
            'queue' => $this->queue,
            'job_id' => $jobId,
            'operator_id' => auth()->user()?->getAuthIdentifier(),
        ]);
    }
}
