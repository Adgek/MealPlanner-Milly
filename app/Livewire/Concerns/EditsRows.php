<?php

namespace App\Livewire\Concerns;

/**
 * The shape the short lists are edited in (MM-15): the bulk ingredients, the
 * freezer stash, the kid backup foods.
 *
 * Each of those is a handful of fields per row, so they are edited as one form
 * and saved in one go — bump three portion counts, press save once — rather than
 * a page per row. That is MM-4's settings page applied to a list.
 *
 * Removing is the exception, and is deliberately not part of the save. A row
 * that has never been saved is simply dropped; a row that exists is a record
 * something else may point at, so it goes through a confirmation and is removed
 * on its own, immediately, leaving whatever else the household has typed
 * untouched.
 */
trait EditsRows
{
    /**
     * The rows on screen. Each carries its `id`, which is null until it is saved.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $rows = [];

    public bool $saved = false;

    /**
     * The saved row the household is being asked about, by id.
     */
    public ?int $removing = null;

    /**
     * What the last removal did, in a sentence.
     */
    public ?string $status = null;

    /**
     * Any edit retires the "Saved" banner, so it always refers to what is in the
     * database rather than to what is on screen (MM-4).
     */
    public function updated(string $property): void
    {
        $this->saved = false;
    }

    public function addRow(): void
    {
        $this->rows[] = $this->blankRow();
        $this->saved = false;
        $this->status = null;
    }

    /**
     * Drop a row that was never saved. There is nothing to warn about, because
     * nothing can point at a record that does not exist yet.
     */
    public function dropRow(int $index): void
    {
        if (($this->rows[$index]['id'] ?? null) !== null) {
            return;
        }

        unset($this->rows[$index]);

        $this->rows = array_values($this->rows);
        $this->saved = false;

        $this->resetValidation();
    }

    public function confirmRemove(int $id): void
    {
        $this->removing = $id;
        $this->status = null;
        $this->saved = false;
    }

    public function cancelRemove(): void
    {
        $this->removing = null;
    }

    /**
     * Take a removed row off the form, keeping every other row exactly as the
     * household has it — including the edits they have not saved yet.
     */
    protected function forgetRow(int $id): void
    {
        $this->rows = array_values(array_filter(
            $this->rows,
            fn (array $row): bool => ($row['id'] ?? null) !== $id,
        ));

        $this->removing = null;
    }

    /**
     * An empty row, ready to be typed into.
     *
     * @return array<string, mixed>
     */
    abstract protected function blankRow(): array;
}
