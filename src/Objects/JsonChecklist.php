<?php

declare(strict_types=1);

namespace LaraGram\Server\Objects;

use LaraGram\Server\Bot\Client;
use LaraGram\Server\Entities\FormattedText;

/**
 * The Bot API `Checklist` and `ChecklistTask` (Client::JsonChecklist,
 * Client::JsonChecklistTask) of td_api-like checklists.
 */
final class JsonChecklist
{
    /**
     * @param array<string, mixed> $checklist ['title' => FormattedText, 'tasks', 'others_can_add_tasks', 'others_can_mark_tasks_as_done']
     * @return array<string, mixed>
     */
    public static function make(array $checklist, Client $client): array
    {
        $object = ['title' => $checklist['title']->text];
        if ($checklist['title']->entities !== []) {
            $object['title_entities'] = JsonEntity::vector($checklist['title']->entities, $client);
        }
        $object['tasks'] = array_map(static fn (array $task): array => self::task($task, $client), $checklist['tasks']);
        if ($checklist['others_can_add_tasks']) {
            $object['others_can_add_tasks'] = true;
        }
        if ($checklist['others_can_mark_tasks_as_done']) {
            $object['others_can_mark_tasks_as_done'] = true;
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $task ['id', 'text' => FormattedText, 'completed_by' => ?sender, 'completion_date']
     * @return array<string, mixed>
     */
    public static function task(array $task, Client $client): array
    {
        $object = ['id' => $task['id'], 'text' => $task['text']->text];
        if ($task['text']->entities !== []) {
            $object['text_entities'] = JsonEntity::vector($task['text']->entities, $client);
        }
        if ($task['completed_by'] !== null && $task['completion_date'] !== 0) {
            if ($task['completed_by']['_'] === 'messageSenderUser') {
                $object['completed_by_user'] = JsonUser::make((int) $task['completed_by']['user_id'], $client);
            } else {
                $object['completed_by_chat'] = JsonChat::make((int) $task['completed_by']['chat_id'], $client);
            }
            $object['completion_date'] = $task['completion_date'];
        }

        return $object;
    }

    /**
     * ToDoList(user_manager, telegram_api::todoList) with its completions →
     * ToDoList::get_checklist_object.
     *
     * @param array<string, mixed> $list TL todoList
     * @param list<array<string, mixed>> $completions TL todoCompletion
     * @return array<string, mixed>
     */
    public static function fromServer(array $list, array $completions, int $messageDate): array
    {
        $valid = [];
        foreach ($completions as $completion) {
            if (ChatId::fromPeer($completion['completed_by'] ?? null) !== 0 && (int) ($completion['date'] ?? 0) > 0) {
                $valid[] = $completion;
            }
        }

        $tasks = [];
        foreach ($list['list'] ?? [] as $item) {
            $match = null;
            foreach ($valid as $completion) {
                if ((int) $completion['id'] === (int) ($item['id'] ?? 0)) {
                    $match = $completion;
                }
            }
            $tasks[] = self::taskFromServer($item, $messageDate, $match);
        }

        return [
            'title' => FormattedText::fromTextWithEntities($list['title'] ?? null, true, true)->removeUnallowedQuoteEntities(),
            'tasks' => $tasks,
            'others_can_add_tasks' => !empty($list['others_can_append']),
            'others_can_mark_tasks_as_done' => !empty($list['others_can_complete']),
        ];
    }

    /**
     * ToDoItem(TL todoItem) + its completion.
     *
     * @param array<string, mixed> $item TL todoItem
     * @param array<string, mixed>|null $completion TL todoCompletion
     * @return array<string, mixed>
     */
    public static function taskFromServer(array $item, int $messageDate, ?array $completion = null): array
    {
        $completedBy = null;
        if ($completion !== null) {
            $by = ChatId::fromPeer($completion['completed_by'] ?? null);
            if ($by !== 0) {
                $completedBy = $by > 0 ? ['_' => 'messageSenderUser', 'user_id' => $by] : ['_' => 'messageSenderChat', 'chat_id' => $by];
            }
        }
        $text = FormattedText::fromTextWithEntities($item['title'] ?? null, true, true)->removeUnallowedQuoteUserEntities(true, true);
        if (!mb_check_encoding($text->text, 'UTF-8')) {
            $text = new FormattedText();
        }

        return [
            'id' => (int) ($item['id'] ?? 0),
            'text' => $text,
            'completed_by' => $completedBy,
            'completion_date' => $completedBy === null ? 0 : (int) ($completion['date'] ?? 0),
        ];
    }
}
