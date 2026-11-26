import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

const STEPS = [
    { number: 1, title: 'Details', fields: ['name'] },
    { number: 2, title: 'Domains', fields: ['domain', 'additional_domains'] },
    { number: 3, title: 'Branding', fields: ['brand_color', 'logo'] },
    {
        number: 4,
        title: 'Tenant admin',
        fields: ['admin_name', 'admin_email', 'admin_password'],
    },
];

const stepForField = (field) =>
    STEPS.find((step) =>
        step.fields.some((name) => field === name || field.startsWith(`${name}.`)),
    )?.number;

function Progress({ current, reached, onSelect }) {
    return (
        <ol className="mb-8 grid grid-cols-2 gap-3 sm:grid-cols-4">
            {STEPS.map((step) => {
                const done = step.number < current;
                const active = step.number === current;
                const reachable = step.number <= reached;

                return (
                    <li key={step.number}>
                        <button
                            type="button"
                            disabled={!reachable}
                            onClick={() => onSelect(step.number)}
                            aria-current={active ? 'step' : undefined}
                            className={`flex w-full items-center gap-3 rounded-md border px-3 py-2 text-left text-sm ${
                                active
                                    ? 'border-indigo-600 bg-indigo-50 text-indigo-800'
                                    : done
                                      ? 'border-green-300 bg-green-50 text-green-800'
                                      : 'border-gray-200 text-gray-500'
                            } disabled:cursor-not-allowed`}
                        >
                            <span
                                className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${
                                    active
                                        ? 'bg-indigo-600 text-white'
                                        : done
                                          ? 'bg-green-600 text-white'
                                          : 'bg-gray-200 text-gray-600'
                                }`}
                            >
                                {done ? '✓' : step.number}
                            </span>
                            {step.title}
                        </button>
                    </li>
                );
            })}
        </ol>
    );
}

export default function Create({ maxAdditionalDomains }) {
    const [step, setStep] = useState(1);
    const [reached, setReached] = useState(1);
    const { data, setData, post, processing, errors, clearErrors } = useForm({
        name: '',
        domain: '',
        additional_domains: [],
        brand_color: '#1d4ed8',
        logo: null,
        admin_name: '',
        admin_email: '',
        admin_password: '',
        admin_password_confirmation: '',
    });

    const goTo = (number) => {
        clearErrors();
        setStep(number);
    };

    const next = (event) => {
        event.preventDefault();

        if (step === STEPS.length) {
            post(route('admin.tenants.store'), {
                forceFormData: true,
                preserveScroll: true,
                onError: (failed) => {
                    const steps = Object.keys(failed)
                        .map(stepForField)
                        .filter(Boolean);

                    if (steps.length > 0) {
                        setStep(Math.min(...steps));
                    }
                },
            });

            return;
        }

        post(route('admin.tenants.create.validate', step), {
            forceFormData: true,
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                setStep(step + 1);
                setReached(Math.max(reached, step + 1));
            },
        });
    };

    const updateAdditionalDomain = (index, value) =>
        setData(
            'additional_domains',
            data.additional_domains.map((domain, i) => (i === index ? value : domain)),
        );

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
                <div className="mx-auto max-w-3xl sm:px-6 lg:px-8">
                    <div className="bg-white p-6 shadow-sm sm:rounded-lg">
                        <Progress current={step} reached={reached} onSelect={goTo} />

                        {errors.provisioning && (
                            <p className="mb-6 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
                                {errors.provisioning}
                            </p>
                        )}

                        <form onSubmit={next} className="space-y-6">
                            {step === 1 && (
                                <div>
                                    <InputLabel htmlFor="name" value="Blog name" />
                                    <TextInput
                                        id="name"
                                        className="mt-1 block w-full"
                                        value={data.name}
                                        maxLength={255}
                                        onChange={(event) => setData('name', event.target.value)}
                                        isFocused
                                    />
                                    <p className="mt-1 text-xs text-gray-500">
                                        Shown on the tenant&apos;s public blog.
                                    </p>
                                    <InputError className="mt-2" message={errors.name} />
                                </div>
                            )}

                            {step === 2 && (
                                <>
                                    <div>
                                        <InputLabel htmlFor="domain" value="Primary domain" />
                                        <TextInput
                                            id="domain"
                                            className="mt-1 block w-full"
                                            value={data.domain}
                                            maxLength={253}
                                            placeholder="acme.platform.test"
                                            onChange={(event) => setData('domain', event.target.value)}
                                            isFocused
                                        />
                                        <p className="mt-1 text-xs text-gray-500">
                                            A platform subdomain or a custom domain such as blog.acme.com.
                                        </p>
                                        <InputError className="mt-2" message={errors.domain} />
                                    </div>

                                    <div>
                                        <InputLabel value="Additional domains (optional)" />
                                        <div className="mt-1 space-y-2">
                                            {data.additional_domains.map((domain, index) => (
                                                <div key={index}>
                                                    <div className="flex gap-2">
                                                        <TextInput
                                                            aria-label={`Additional domain ${index + 1}`}
                                                            className="block w-full"
                                                            value={domain}
                                                            maxLength={253}
                                                            onChange={(event) =>
                                                                updateAdditionalDomain(index, event.target.value)
                                                            }
                                                        />
                                                        <SecondaryButton
                                                            onClick={() =>
                                                                setData(
                                                                    'additional_domains',
                                                                    data.additional_domains.filter(
                                                                        (_, i) => i !== index,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            Remove
                                                        </SecondaryButton>
                                                    </div>
                                                    <InputError
                                                        className="mt-1"
                                                        message={errors[`additional_domains.${index}`]}
                                                    />
                                                </div>
                                            ))}
                                        </div>
                                        {data.additional_domains.length < maxAdditionalDomains && (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setData('additional_domains', [
                                                        ...data.additional_domains,
                                                        '',
                                                    ])
                                                }
                                                className="mt-2 text-sm text-indigo-600 underline hover:text-indigo-800"
                                            >
                                                Add another domain
                                            </button>
                                        )}
                                        <InputError className="mt-2" message={errors.additional_domains} />
                                    </div>
                                </>
                            )}

                            {step === 3 && (
                                <>
                                    <div>
                                        <InputLabel htmlFor="brand_color" value="Brand color" />
                                        <div className="mt-1 flex items-center gap-3">
                                            <input
                                                id="brand_color"
                                                type="color"
                                                className="h-10 w-20 rounded-md border-gray-300"
                                                value={data.brand_color}
                                                onChange={(event) =>
                                                    setData('brand_color', event.target.value)
                                                }
                                            />
                                            <span className="font-mono text-sm text-gray-700">
                                                {data.brand_color}
                                            </span>
                                        </div>
                                        <InputError className="mt-2" message={errors.brand_color} />
                                    </div>

                                    <div>
                                        <InputLabel htmlFor="logo" value="Logo" />
                                        <input
                                            id="logo"
                                            type="file"
                                            accept="image/png,image/jpeg,image/webp"
                                            className="mt-1 block w-full text-sm text-gray-700"
                                            onChange={(event) =>
                                                setData('logo', event.target.files[0] ?? null)
                                            }
                                        />
                                        <p className="mt-1 text-xs text-gray-500">
                                            {data.logo
                                                ? `Selected: ${data.logo.name}`
                                                : 'JPG, PNG or WebP, up to 2 MB.'}
                                        </p>
                                        <InputError className="mt-2" message={errors.logo} />
                                    </div>
                                </>
                            )}

                            {step === 4 && (
                                <>
                                    <p className="text-sm text-gray-600">
                                        This account becomes the tenant admin and signs in at{' '}
                                        <span className="font-mono">{data.domain || 'the primary domain'}/login</span>.
                                    </p>
                                    <div>
                                        <InputLabel htmlFor="admin_name" value="Name" />
                                        <TextInput
                                            id="admin_name"
                                            className="mt-1 block w-full"
                                            value={data.admin_name}
                                            maxLength={255}
                                            autoComplete="off"
                                            onChange={(event) => setData('admin_name', event.target.value)}
                                            isFocused
                                        />
                                        <InputError className="mt-2" message={errors.admin_name} />
                                    </div>
                                    <div>
                                        <InputLabel htmlFor="admin_email" value="Email" />
                                        <TextInput
                                            id="admin_email"
                                            type="email"
                                            className="mt-1 block w-full"
                                            value={data.admin_email}
                                            autoComplete="off"
                                            onChange={(event) => setData('admin_email', event.target.value)}
                                        />
                                        <InputError className="mt-2" message={errors.admin_email} />
                                    </div>
                                    <div>
                                        <InputLabel htmlFor="admin_password" value="Password" />
                                        <TextInput
                                            id="admin_password"
                                            type="password"
                                            className="mt-1 block w-full"
                                            value={data.admin_password}
                                            autoComplete="new-password"
                                            onChange={(event) => setData('admin_password', event.target.value)}
                                        />
                                        <InputError className="mt-2" message={errors.admin_password} />
                                    </div>
                                    <div>
                                        <InputLabel
                                            htmlFor="admin_password_confirmation"
                                            value="Confirm password"
                                        />
                                        <TextInput
                                            id="admin_password_confirmation"
                                            type="password"
                                            className="mt-1 block w-full"
                                            value={data.admin_password_confirmation}
                                            autoComplete="new-password"
                                            onChange={(event) =>
                                                setData('admin_password_confirmation', event.target.value)
                                            }
                                        />
                                    </div>
                                </>
                            )}

                            <div className="flex items-center justify-between border-t border-gray-200 pt-6">
                                {step > 1 ? (
                                    <SecondaryButton onClick={() => goTo(step - 1)} disabled={processing}>
                                        Back
                                    </SecondaryButton>
                                ) : (
                                    <Link
                                        href={route('admin.tenants.index')}
                                        className="text-sm text-gray-600 underline hover:text-gray-900"
                                    >
                                        Cancel
                                    </Link>
                                )}
                                <PrimaryButton disabled={processing}>
                                    {step === STEPS.length
                                        ? processing
                                            ? 'Creating tenant…'
                                            : 'Create tenant'
                                        : processing
                                          ? 'Checking…'
                                          : 'Next'}
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
