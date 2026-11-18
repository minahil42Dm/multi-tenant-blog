import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        domain: '',
        brand_color: '#1d4ed8',
        logo: null,
    });

    const submit = (event) => {
        event.preventDefault();

        post(route('admin.tenants.store'), {
            forceFormData: true,
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Create tenant
                </h2>
            }
        >
            <Head title="Create tenant" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="bg-white p-6 shadow-sm sm:rounded-lg">
                        <form onSubmit={submit} className="space-y-6">
                            <div>
                                <InputLabel htmlFor="name" value="Tenant name" />
                                <TextInput
                                    id="name"
                                    className="mt-1 block w-full"
                                    value={data.name}
                                    onChange={(event) =>
                                        setData('name', event.target.value)
                                    }
                                    required
                                    isFocused
                                />
                                <InputError className="mt-2" message={errors.name} />
                            </div>

                            <div>
                                <InputLabel htmlFor="domain" value="Domain" />
                                <TextInput
                                    id="domain"
                                    className="mt-1 block w-full"
                                    value={data.domain}
                                    onChange={(event) =>
                                        setData('domain', event.target.value)
                                    }
                                    placeholder="acme.localhost"
                                    required
                                />
                                <InputError
                                    className="mt-2"
                                    message={errors.domain}
                                />
                            </div>

                            <div>
                                <InputLabel htmlFor="logo" value="Logo" />
                                <input
                                    id="logo"
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp"
                                    className="mt-1 block w-full text-sm text-gray-700"
                                    onChange={(event) =>
                                        setData(
                                            'logo',
                                            event.target.files[0] ?? null,
                                        )
                                    }
                                    required
                                />
                                <InputError className="mt-2" message={errors.logo} />
                            </div>

                            <div>
                                <InputLabel
                                    htmlFor="brand_color"
                                    value="Brand color"
                                />
                                <input
                                    id="brand_color"
                                    type="color"
                                    className="mt-1 block h-10 w-20 rounded-md border-gray-300"
                                    value={data.brand_color}
                                    onChange={(event) =>
                                        setData(
                                            'brand_color',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                                <InputError
                                    className="mt-2"
                                    message={errors.brand_color}
                                />
                            </div>

                            <PrimaryButton disabled={processing}>
                                Create tenant
                            </PrimaryButton>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
