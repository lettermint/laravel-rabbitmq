<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Filament\Pages;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Actions\Dlq\InspectDlqMessages;
use Lettermint\RabbitMQ\Actions\Dlq\PurgeDlqMessages;
use Lettermint\RabbitMQ\Actions\Dlq\ReplayDlqMessages;
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

    public function mount(): void
    {
        $queues = $this->deadLetterQueues();
        $requested = request()->string('queue')->toString();
        $this->queue = in_array($requested, $queues, true)
            ? $requested
            : ($queues[0] ?? '');
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->records())
            ->columns([
                TextColumn::make('id')->label('Job ID')->copyable(),
                TextColumn::make('job_class')->label('Job')->formatStateUsing(fn (string $state): string => class_basename($state)),
                TextColumn::make('attempts')->numeric(),
                TextColumn::make('failed_at')->dateTime()->placeholder('Unknown'),
                TextColumn::make('reason')->badge(),
                TextColumn::make('exception')->wrap()->limit(100)->placeholder('Use the failed-job provider for details'),
            ])
            ->recordActions([
                Action::make('inspect')
                    ->modalHeading('Dead-letter details')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (array $record) => app(ViewFactory::class)->make('rabbitmq::filament.dead-letter-details', [
                        'record' => $record,
                    ])),
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
    protected function records(): array
    {
        if ($this->queue === '') {
            return [];
        }

        $result = app(InspectDlqMessages::class)($this->queue, limit: 100);
        $failedProvider = app()->bound('queue.failer') ? app('queue.failer') : null;

        return array_map(function ($message) use ($failedProvider): array {
            $failed = $this->findFailureDetails($failedProvider, $message->id);

            return [
                'key' => $message->id,
                'id' => $message->id,
                'job_class' => $message->jobClass,
                'attempts' => $message->attempts,
                'failed_at' => $message->failedAt,
                'reason' => $message->reason,
                'exception' => is_object($failed) ? ($failed->exception ?? null) : ($message->exception['message'] ?? null),
                'payload' => $message->payload,
                'headers_note' => 'Broker delivery headers are not shown.',
            ];
        }, $result->messages);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('selectQueue')
                ->label('Select queue')
                ->fillForm(['queue' => $this->queue])
                ->schema([
                    Select::make('queue')
                        ->options(array_combine($this->deadLetterQueues(), $this->deadLetterQueues()))
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->queue = (string) $data['queue'];
                    $this->resetTable();
                }),
        ];
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
            report($exception);
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
            report($exception);
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
