import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Link, useForm } from '@inertiajs/react';

const fields = [
    ['name', 'Name', 'text', true],
    ['email', 'Email', 'email', false],
    ['phone', 'Phone', 'text', false],
    ['company', 'Company', 'text', false],
    ['job_title', 'Job title', 'text', false],
    ['website', 'Website', 'text', false],
    ['location', 'Location', 'text', false],
];

export default function Form({ mode, contact, tags }) {
    const { data, setData, post, put, processing, errors } = useForm({
        name: contact?.name ?? '',
        email: contact?.email ?? '',
        phone: contact?.phone ?? '',
        company: contact?.company ?? '',
        job_title: contact?.job_title ?? '',
        website: contact?.website ?? '',
        location: contact?.location ?? '',
        tags: contact?.tags?.map((tag) => tag.id) ?? [],
    });

    const submit = (event) => {
        event.preventDefault();

        if (mode === 'create') {
            post(route('writer.contacts.store'));

            return;
        }

        put(route('writer.contacts.update', contact.id));
    };

    const toggleTag = (id) => {
        setData(
            'tags',
            data.tags.includes(id)
                ? data.tags.filter((tagId) => tagId !== id)
                : [...data.tags, id],
        );
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            {fields.map(([name, label, type, required]) => (
                <div key={name}>
                    <InputLabel htmlFor={name} value={label} />
                    <TextInput
                        id={name}
                        type={type}
                        className="mt-1 block w-full"
                        value={data[name] ?? ''}
                        onChange={(event) => setData(name, event.target.value)}
                        required={required}
                        isFocused={name === 'name'}
                    />
                    <InputError className="mt-2" message={errors[name]} />
                </div>
            ))}

            <fieldset>
                <legend className="text-sm font-medium text-gray-700">Tags</legend>
                {tags.length === 0 && (
                    <p className="mt-2 text-sm text-gray-600">
                        No tags yet. Create one from the contacts list.
                    </p>
                )}
                <div className="mt-2 flex flex-wrap gap-3">
                    {tags.map((tag) => (
                        <label key={tag.id} className="flex items-center gap-2 text-sm text-gray-700">
                            <input
                                type="checkbox"
                                checked={data.tags.includes(tag.id)}
                                onChange={() => toggleTag(tag.id)}
                            />
                            {tag.name}
                        </label>
                    ))}
                </div>
                <InputError className="mt-2" message={errors.tags} />
            </fieldset>

            <div className="flex items-center gap-4">
                <PrimaryButton disabled={processing}>
                    {mode === 'create' ? 'Create contact' : 'Save changes'}
                </PrimaryButton>
                <Link
                    href={
                        mode === 'create'
                            ? route('writer.contacts.index')
                            : route('writer.contacts.show', contact.id)
                    }
                    className="text-sm text-gray-600 underline hover:text-gray-900"
                >
                    Cancel
                </Link>
            </div>
        </form>
    );
}
