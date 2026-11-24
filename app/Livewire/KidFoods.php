<?php

namespace App\Livewire;

use App\Livewire\Concerns\EditsRows;
use App\Models\KidBackupFood;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The kid off-ramp inventory (MM-15, AppPlan.md section 3): the guaranteed-safe
 * backup foods to keep stocked alongside the regular groceries.
 *
 * Three fields a row, so it is one form and one save. The amount is split into a
 * quantity and a unit, the same shape as an ingredient, which is what lets the
 * grocery list carry these beside everything else.
 *
 * Nothing on a plan points at a kid backup food, so removing one is a plain
 * delete — and the grocery list is stored rather than recomputed (MM-7), so a
 * list already in somebody's hand keeps the line it was built with until it is
 * read again.
 */
#[Title('Kid foods')]
class KidFoods extends Component
{
    use EditsRows;

    public function mount(): void
    {
        $this->rows = KidBackupFood::query()
            ->orderBy('name')
            ->get()
            ->map(fn (KidBackupFood $food): array => [
                'id' => (int) $food->getKey(),
                'name' => $food->name,
                'quantity' => $food->quantity === null ? '' : $this->tidyAmount($food->quantity),
                'unit' => (string) $food->unit,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = ['rows' => ['array', 'max:60']];

        foreach ($this->rows as $index => $row) {
            $rules["rows.{$index}.name"] = [
                'required',
                'string',
                'max:120',
                Rule::unique('kid_backup_foods', 'name')->ignore($row['id'] ?? null),
                $this->notListedTwice(),
            ];

            // The same bounds as an ingredient's amount, because the grocery list
            // puts the two side by side — including "more than zero", since a
            // zero would put a line on the list telling the household to buy none
            // of it.
            $rules["rows.{$index}.quantity"] = ['nullable', 'numeric', 'min:0.01', 'max:999999.99', 'decimal:0,2'];
            $rules["rows.{$index}.unit"] = ['nullable', 'string', 'max:40'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'rows.*.name.required' => 'Name the backup food, or remove the row.',
            'rows.*.name.unique' => 'That backup food is already on the list.',
            'rows.*.name.max' => 'Keep the name to 120 characters or fewer.',
            'rows.*.quantity.numeric' => 'Give the amount as a number, like 1 or 2. The unit goes in its own box.',
            'rows.*.quantity.decimal' => 'Amounts are stored to two decimal places. Round it first.',
            'rows.*.quantity.min' => 'An amount has to be more than zero. Leave it empty for something '
                .'you do not count.',
            'rows.*.quantity.max' => 'That is a bigger amount than the list can hold.',
            'rows.*.unit.max' => 'Keep the unit short, like box, bag or loaf.',
        ];
    }

    /**
     * Save every row, or none of them.
     */
    public function save(): void
    {
        $this->saved = false;
        $this->status = null;

        $this->tidyInput();
        $this->validate();

        DB::transaction(function (): void {
            foreach ($this->rows as $index => $row) {
                $food = KidBackupFood::query()->findOrNew($row['id'] ?? null);

                $food->fill([
                    'name' => $row['name'],
                    'quantity' => $row['quantity'] === '' ? null : (float) $row['quantity'],
                    'unit' => $row['unit'] === '' ? null : $row['unit'],
                ])->save();

                $this->rows[$index]['id'] = (int) $food->getKey();
            }
        });

        $this->saved = true;
    }

    /**
     * Remove a backup food.
     *
     * Nothing points at it, so this is the one removal on these screens with
     * nothing to repair: it simply stops being added to the next grocery list.
     */
    public function remove(int $id): void
    {
        $food = KidBackupFood::query()->find($id);

        if ($food === null) {
            $this->forgetRow($id);
            $this->status = 'That backup food is already gone.';

            return;
        }

        $name = $food->name;
        $food->delete();

        $this->forgetRow($id);
        $this->status = $name.' is off the list. The next grocery list will not include it.';
    }

    public function render(): View
    {
        return view('livewire.kid-foods');
    }

    /**
     * @return array{id: null, name: string, quantity: string, unit: string}
     */
    protected function blankRow(): array
    {
        return ['id' => null, 'name' => '', 'quantity' => '1', 'unit' => ''];
    }

    private function tidyInput(): void
    {
        foreach ($this->rows as $index => $row) {
            $this->rows[$index]['name'] = trim((string) ($row['name'] ?? ''));
            $this->rows[$index]['quantity'] = trim((string) ($row['quantity'] ?? ''));
            $this->rows[$index]['unit'] = trim((string) ($row['unit'] ?? ''));
        }
    }

    /**
     * "1.00" reads as "1" in a form, and "0.50" as "0.5".
     */
    private function tidyAmount(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * Two rows of the same name would both pass the `unique` rule — neither is in
     * the database yet — and then collide on the index halfway through saving.
     */
    private function notListedTwice(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $name = mb_strtolower(trim((string) $value));
            $index = (int) explode('.', $attribute)[1];

            foreach ($this->rows as $other => $row) {
                if ($other !== $index && mb_strtolower(trim((string) ($row['name'] ?? ''))) === $name) {
                    $fail('That name is on the list twice.');

                    return;
                }
            }
        };
    }
}
