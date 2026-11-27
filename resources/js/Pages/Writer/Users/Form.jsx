import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Link, useForm } from '@inertiajs/react';

export default function Form({ mode, user, roles }) {
    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        role: user?.role ?? 'writer',
        password: '',
        password_confirmation: '',
        current_password: '',
    });

    const submit = (event) => {
        event.preventDefault();

        const options = {
            onFinish: () =>
                reset('password', 'password_confirmation', 'current_password'),
        };

        if (mode === 'create') {
            post(route('writer.users.store'), options);

            return;
        }

        put(route('writer.users.update', user.id), options);
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <div>
                <InputLabel htmlFor="name" value="Name" />
                <TextInput
                    id="name"
                    className="mt-1 block w-full"
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                    required
                    isFocused
                />
                <InputError className="mt-2" message={errors.name} />
            </div>

            <div>
                <InputLabel htmlFor="email" value="Email" />
                <TextInput
                    id="email"
                    type="email"
                    className="mt-1 block w-full"
                    value={data.email}
                    autoComplete="off"
                    onChange={(event) => setData('email', event.target.value)}
                    required
                />
                <InputError className="mt-2" message={errors.email} />
            </div>

            <div>
                <InputLabel htmlFor="role" value="Role" />
                <select
                    id="role"
                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    value={data.role}
                    disabled={user && !user.can_change_role}
                    onChange={(event) => setData('role', event.target.value)}
                >
                    {roles.map((role) => (
                        <option key={role.value} value={role.value}>
                            {role.label}
                        </option>
                    ))}
                </select>
                {user && !user.can_change_role && (
                    <p className="mt-2 text-sm text-gray-600">
                        This role cannot be changed. A tenant admin cannot demote
                        themselves, and the last tenant admin must stay an admin.
                    </p>
                )}
                <InputError className="mt-2" message={errors.role} />
            </div>

            {mode === 'edit' && user?.is_self && (
                <div>
                    <InputLabel
                        htmlFor="current_password"
                        value="Current password"
                    />
                    <TextInput
                        id="current_password"
                        type="password"
                        className="mt-1 block w-full"
                        value={data.current_password}
                        autoComplete="current-password"
                        onChange={(event) =>
                            setData('current_password', event.target.value)
                        }
                    />
                    <p className="mt-2 text-sm text-gray-600">
                        Required only when you change your own password.
                    </p>
                    <InputError
                        className="mt-2"
                        message={errors.current_password}
                    />
                </div>
            )}

            <div>
                <InputLabel
                    htmlFor="password"
                    value={mode === 'create' ? 'Password' : 'New password'}
                />
                <TextInput
                    id="password"
                    type="password"
                    className="mt-1 block w-full"
                    value={data.password}
                    autoComplete="new-password"
                    onChange={(event) => setData('password', event.target.value)}
                    required={mode === 'create'}
                />
                {mode === 'edit' && (
                    <p className="mt-2 text-sm text-gray-600">
                        Leave this blank to keep the current password.
                    </p>
                )}
                <InputError className="mt-2" message={errors.password} />
            </div>

            <div>
                <InputLabel
                    htmlFor="password_confirmation"
                    value="Confirm password"
                />
                <TextInput
                    id="password_confirmation"
                    type="password"
                    className="mt-1 block w-full"
                    value={data.password_confirmation}
                    autoComplete="new-password"
                    onChange={(event) =>
                        setData('password_confirmation', event.target.value)
                    }
                    required={mode === 'create'}
                />
            </div>

            <div className="flex items-center gap-4">
                <PrimaryButton disabled={processing}>
                    {mode === 'create' ? 'Create user' : 'Save changes'}
                </PrimaryButton>
                <Link
                    href={route('writer.users.index')}
                    className="text-sm text-gray-600 underline hover:text-gray-900"
                >
                    Cancel
                </Link>
            </div>
        </form>
    );
}
