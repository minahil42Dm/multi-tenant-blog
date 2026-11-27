<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\Writer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactNote>
 */
class ContactNoteFactory extends Factory
{
    protected $model = ContactNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => function () {
                $owner = Writer::factory()->create();
                $contact = Contact::factory()->make();
                $contact->owner_id = $owner->id;
                $contact->save();

                return $contact->id;
            },
            'user_id' => Writer::factory(),
            'body' => fake()->paragraph(),
        ];
    }
}
