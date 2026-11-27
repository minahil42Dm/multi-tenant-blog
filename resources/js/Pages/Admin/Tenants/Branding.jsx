import FlashMessages from '@/Components/FlashMessages';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TenantBrandMark from '@/Components/TenantBrandMark';
import TenantFooter from '@/Components/TenantFooter';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

function useObjectUrl(file) {
    const [url, setUrl] = useState(null);

    useEffect(() => {
        if (!file) {
            setUrl(null);

            return undefined;
        }

        const objectUrl = URL.createObjectURL(file);
        setUrl(objectUrl);

        return () => URL.revokeObjectURL(objectUrl);
    }, [file]);

    return url;
}

function ColorField({ id, label, value, onChange, error, disabled }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <div className="mt-1 flex items-center gap-3">
                <input
                    id={id}
                    type="color"
                    className="h-10 w-16 rounded border border-gray-300 disabled:opacity-60"
                    value={value}
                    disabled={disabled}
                    onChange={(event) => onChange(event.target.value)}
                />
                <TextInput
                    aria-label={`${label} hex value`}
                    className="w-32 font-mono"
                    value={value}
                    maxLength={7}
                    disabled={disabled}
                    onChange={(event) => onChange(event.target.value)}
                />
            </div>
            <InputError message={error} className="mt-2" />
        </div>
    );
}

function FileField({ id, label, hint, accept, currentUrl, onChange, error, disabled, children }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            {currentUrl && (
                <img
                    src={currentUrl}
                    alt={`Current ${label.toLowerCase()}`}
                    className="mt-2 h-12 w-auto rounded border border-gray-200 bg-white object-contain p-1"
                />
            )}
            {!disabled && (
                <input
                    id={id}
                    type="file"
                    accept={accept}
                    className="mt-2 block w-full text-sm text-gray-700"
                    onChange={(event) => onChange(event.target.files[0] ?? null)}
                />
            )}
            <p className="mt-1 text-xs text-gray-500">{hint}</p>
            {children}
            <InputError message={error} className="mt-2" />
        </div>
    );
}

function Preview({ branding, faviconUrl }) {
    const links = branding.footer_links.filter((link) => link.label && link.url);

    return (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-gray-100 shadow-sm">
            <div className="flex items-center gap-2 border-b border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600">
                {faviconUrl ? (
                    <img src={faviconUrl} alt="" className="h-4 w-4 object-contain" />
                ) : (
                    <span className="inline-block h-4 w-4 rounded-sm bg-gray-300" />
                )}
                <span className="truncate">Dashboard - {branding.name || 'Blog'}</span>
            </div>
            <div
                className="flex h-14 items-center justify-between border-t-4 bg-white px-4"
                style={{ borderTopColor: branding.color }}
            >
                <TenantBrandMark branding={branding} showTagline logoClassName="h-8 w-8" textClassName="text-base" />
                <span className="text-xs text-gray-500">Tenant menu</span>
            </div>
            <div className="space-y-3 p-4">
                <p className="text-lg font-semibold" style={{ color: branding.color }}>
                    {branding.name || 'Blog'}
                </p>
                <p className="text-sm text-gray-600">Sample post excerpt on the public blog.</p>
                <span
                    className="inline-block rounded-md px-3 py-1 text-xs font-semibold text-white"
                    style={{ backgroundColor: branding.accent_color }}
                >
                    Accent button
                </span>
            </div>
            <TenantFooter branding={{ ...branding, footer_links: links }} />
        </div>
    );
}

export default function Branding({ tenant, branding, options }) {
    const can = usePage().props.auth.can ?? {};
    const readOnly = !can.manageTenants;

    const { data, setData, post, processing, errors, progress } = useForm({
        _method: 'put',
        name: branding.name,
        brand_color: branding.brand_color,
        accent_color: branding.accent_color,
        support_email: branding.support_email ?? '',
        sidebar_style: branding.sidebar_style,
        sidebar_tagline: branding.sidebar_tagline ?? '',
        sidebar_links: branding.sidebar_links ?? [],
        footer_links: branding.footer_links,
        logo: null,
        favicon: null,
        remove_favicon: false,
    });

    const logoPreview = useObjectUrl(data.logo);
    const faviconPreview = useObjectUrl(data.favicon);

    const previewBranding = useMemo(
        () => ({
            name: data.name,
            color: /^#[0-9a-f]{6}$/i.test(data.brand_color) ? data.brand_color : '#1d4ed8',
            accent_color: /^#[0-9a-f]{6}$/i.test(data.accent_color) ? data.accent_color : '#f59e0b',
            logo_url: logoPreview ?? branding.logo_url,
            support_email: data.support_email,
            sidebar_style: data.sidebar_style,
            sidebar_tagline: data.sidebar_tagline,
            sidebar_links: data.sidebar_links,
            footer_links: data.footer_links,
        }),
        [data, logoPreview, branding.logo_url],
    );

    const faviconUrl = data.remove_favicon ? null : (faviconPreview ?? branding.favicon_url);

    const updateLink = (index, field, value) =>
        setData(
            'footer_links',
            data.footer_links.map((link, i) => (i === index ? { ...link, [field]: value } : link)),
        );

    const moveFooterLink = (index, direction) => {
        const next = index + direction;

        if (next < 0 || next >= data.footer_links.length) {
            return;
        }

        const links = [...data.footer_links];
        [links[index], links[next]] = [links[next], links[index]];
        setData('footer_links', links);
    };

    const updateSidebarLink = (index, field, value) =>
        setData(
            'sidebar_links',
            data.sidebar_links.map((link, i) => (i === index ? { ...link, [field]: value } : link)),
        );

    const moveSidebarLink = (index, direction) => {
        const next = index + direction;

        if (next < 0 || next >= data.sidebar_links.length) {
            return;
        }

        const links = [...data.sidebar_links];
        [links[index], links[next]] = [links[next], links[index]];
        setData(
            'sidebar_links',
            links.map((link, order) => ({ ...link, order })),
        );
    };

    const submit = (event) => {
        event.preventDefault();
        post(route('admin.tenants.branding.update', tenant.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => setData((current) => ({ ...current, logo: null, favicon: null, remove_favicon: false })),
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Branding for {tenant.name}
                </h2>
            }
        >
            <Head title={`Branding for ${tenant.name}`} />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <FlashMessages />

                    {readOnly && (
                        <p className="mb-4 rounded-md bg-blue-50 px-4 py-3 text-sm text-blue-800">
                            You can view this tenant&apos;s branding. Only super admins can change it.
                        </p>
                    )}

                    {errors.branding && (
                        <p className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
                            {errors.branding}
                        </p>
                    )}

                    <div className="grid gap-6 lg:grid-cols-5">
                        <form onSubmit={submit} className="space-y-8 lg:col-span-3">
                            <fieldset disabled={readOnly || processing} className="space-y-8">
                                <section className="space-y-6 bg-white p-6 shadow-sm sm:rounded-lg">
                                    <h3 className="text-lg font-medium text-gray-900">Identity</h3>

                                    <div>
                                        <InputLabel htmlFor="name" value="Blog name" />
                                        <TextInput
                                            id="name"
                                            className="mt-1 block w-full"
                                            value={data.name}
                                            maxLength={255}
                                            required
                                            onChange={(event) => setData('name', event.target.value)}
                                        />
                                        <InputError message={errors.name} className="mt-2" />
                                    </div>

                                    <div className="grid gap-6 sm:grid-cols-2">
                                        <ColorField
                                            id="brand_color"
                                            label="Primary color"
                                            value={data.brand_color}
                                            onChange={(value) => setData('brand_color', value)}
                                            error={errors.brand_color}
                                            disabled={readOnly}
                                        />
                                        <ColorField
                                            id="accent_color"
                                            label="Accent color"
                                            value={data.accent_color}
                                            onChange={(value) => setData('accent_color', value)}
                                            error={errors.accent_color}
                                            disabled={readOnly}
                                        />
                                    </div>

                                    <div className="grid gap-6 sm:grid-cols-2">
                                        <FileField
                                            id="logo"
                                            label="Logo"
                                            hint="JPG, PNG or WebP, up to 2 MB and 4096×4096 px. Leave empty to keep the current logo."
                                            accept="image/jpeg,image/png,image/webp"
                                            currentUrl={branding.logo_url}
                                            onChange={(file) => setData('logo', file)}
                                            error={errors.logo}
                                            disabled={readOnly}
                                        />
                                        <FileField
                                            id="favicon"
                                            label="Favicon"
                                            hint="PNG or ICO, up to 512 KB. Square images work best."
                                            accept="image/png,image/x-icon,image/vnd.microsoft.icon,.ico"
                                            currentUrl={branding.favicon_url}
                                            onChange={(file) => setData('favicon', file)}
                                            error={errors.favicon}
                                            disabled={readOnly}
                                        >
                                            {!readOnly && branding.favicon_url && !data.favicon && (
                                                <label className="mt-2 flex items-center gap-2 text-sm text-gray-700">
                                                    <input
                                                        type="checkbox"
                                                        className="rounded border-gray-300"
                                                        checked={data.remove_favicon}
                                                        onChange={(event) => setData('remove_favicon', event.target.checked)}
                                                    />
                                                    Remove the favicon
                                                </label>
                                            )}
                                        </FileField>
                                    </div>
                                    {progress && (
                                        <progress value={progress.percentage} max="100" className="w-full" />
                                    )}
                                </section>

                                <section className="space-y-6 bg-white p-6 shadow-sm sm:rounded-lg">
                                    <h3 className="text-lg font-medium text-gray-900">Navigation and contact</h3>

                                    <div className="grid gap-6 sm:grid-cols-2">
                                        <div>
                                            <InputLabel htmlFor="sidebar_style" value="Navigation branding" />
                                            <select
                                                id="sidebar_style"
                                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                value={data.sidebar_style}
                                                onChange={(event) => setData('sidebar_style', event.target.value)}
                                            >
                                                {options.sidebar_styles.map((option) => (
                                                    <option key={option.value} value={option.value}>
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </select>
                                            <p className="mt-1 text-xs text-gray-500">
                                                Without a logo, the name is always shown.
                                            </p>
                                            <InputError message={errors.sidebar_style} className="mt-2" />
                                        </div>
                                        <div>
                                            <InputLabel htmlFor="sidebar_tagline" value="Navigation tagline (optional)" />
                                            <TextInput
                                                id="sidebar_tagline"
                                                className="mt-1 block w-full"
                                                value={data.sidebar_tagline}
                                                maxLength={80}
                                                onChange={(event) => setData('sidebar_tagline', event.target.value)}
                                            />
                                            <InputError message={errors.sidebar_tagline} className="mt-2" />
                                        </div>
                                    </div>

                                    <div>
                                        <InputLabel value="Sidebar links" />
                                        <div className="mt-2 space-y-3">
                                            {data.sidebar_links.length === 0 && (
                                                <p className="text-sm text-gray-500">No custom navigation links.</p>
                                            )}
                                            {data.sidebar_links.map((link, index) => (
                                                <div key={index}>
                                                    <div className="flex flex-wrap gap-2">
                                                        <TextInput
                                                            aria-label={`Sidebar link ${index + 1} label`}
                                                            className="w-36"
                                                            placeholder="Label"
                                                            value={link.label}
                                                            maxLength={40}
                                                            onChange={(event) => updateSidebarLink(index, 'label', event.target.value)}
                                                        />
                                                        <TextInput
                                                            aria-label={`Sidebar link ${index + 1} URL`}
                                                            className="min-w-40 flex-1"
                                                            placeholder="https://example.com"
                                                            value={link.url}
                                                            maxLength={2048}
                                                            onChange={(event) => updateSidebarLink(index, 'url', event.target.value)}
                                                        />
                                                        <select
                                                            aria-label={`Sidebar link ${index + 1} icon`}
                                                            value={link.icon || 'link'}
                                                            onChange={(event) => updateSidebarLink(index, 'icon', event.target.value)}
                                                            className="rounded-md border-gray-300 text-sm shadow-sm"
                                                        >
                                                            {options.link_icons.map((icon) => (
                                                                <option key={icon} value={icon}>{icon}</option>
                                                            ))}
                                                        </select>
                                                        {!readOnly && (
                                                            <>
                                                                <SecondaryButton type="button" onClick={() => moveSidebarLink(index, -1)}>Up</SecondaryButton>
                                                                <SecondaryButton type="button" onClick={() => moveSidebarLink(index, 1)}>Down</SecondaryButton>
                                                                <SecondaryButton
                                                                    type="button"
                                                                    onClick={() => setData('sidebar_links', data.sidebar_links.filter((_, i) => i !== index).map((item, order) => ({ ...item, order })))}
                                                                >
                                                                    Remove
                                                                </SecondaryButton>
                                                            </>
                                                        )}
                                                    </div>
                                                    <InputError message={errors[`sidebar_links.${index}.label`]} className="mt-1" />
                                                    <InputError message={errors[`sidebar_links.${index}.url`]} className="mt-1" />
                                                    <InputError message={errors[`sidebar_links.${index}.icon`]} className="mt-1" />
                                                </div>
                                            ))}
                                        </div>
                                        {!readOnly && data.sidebar_links.length < options.max_sidebar_links && (
                                            <button
                                                type="button"
                                                onClick={() => setData('sidebar_links', [...data.sidebar_links, { label: '', url: '', icon: 'link', order: data.sidebar_links.length }])}
                                                className="mt-3 text-sm text-indigo-600 underline hover:text-indigo-800"
                                            >
                                                Add sidebar link
                                            </button>
                                        )}
                                        <InputError message={errors.sidebar_links} className="mt-2" />
                                    </div>

                                    <div>
                                        <InputLabel htmlFor="support_email" value="Support email (optional)" />
                                        <TextInput
                                            id="support_email"
                                            type="email"
                                            className="mt-1 block w-full"
                                            value={data.support_email}
                                            maxLength={254}
                                            onChange={(event) => setData('support_email', event.target.value)}
                                        />
                                        <p className="mt-1 text-xs text-gray-500">Shown in the tenant footer.</p>
                                        <InputError message={errors.support_email} className="mt-2" />
                                    </div>

                                    <div>
                                        <InputLabel value="Footer links" />
                                        <div className="mt-2 space-y-3">
                                            {data.footer_links.length === 0 && (
                                                <p className="text-sm text-gray-500">No footer links.</p>
                                            )}
                                            {data.footer_links.map((link, index) => (
                                                <div key={index}>
                                                    <div className="flex flex-wrap gap-2">
                                                        <TextInput
                                                            aria-label={`Footer link ${index + 1} label`}
                                                            className="w-40"
                                                            placeholder="Label"
                                                            value={link.label}
                                                            maxLength={40}
                                                            onChange={(event) => updateLink(index, 'label', event.target.value)}
                                                        />
                                                        <TextInput
                                                            aria-label={`Footer link ${index + 1} URL`}
                                                            className="min-w-48 flex-1"
                                                            placeholder="https://example.com/about"
                                                            value={link.url}
                                                            maxLength={2048}
                                                            onChange={(event) => updateLink(index, 'url', event.target.value)}
                                                        />
                                                        {!readOnly && (
                                                            <>
                                                                <SecondaryButton type="button" onClick={() => moveFooterLink(index, -1)}>Up</SecondaryButton>
                                                                <SecondaryButton type="button" onClick={() => moveFooterLink(index, 1)}>Down</SecondaryButton>
                                                                <SecondaryButton
                                                                    type="button"
                                                                    onClick={() =>
                                                                        setData(
                                                                            'footer_links',
                                                                            data.footer_links.filter((_, i) => i !== index),
                                                                        )
                                                                    }
                                                                >
                                                                    Remove
                                                                </SecondaryButton>
                                                            </>
                                                        )}
                                                    </div>
                                                    <InputError message={errors[`footer_links.${index}.label`]} className="mt-1" />
                                                    <InputError message={errors[`footer_links.${index}.url`]} className="mt-1" />
                                                </div>
                                            ))}
                                        </div>
                                        {!readOnly && data.footer_links.length < options.max_footer_links && (
                                            <button
                                                type="button"
                                                onClick={() => setData('footer_links', [...data.footer_links, { label: '', url: '' }])}
                                                className="mt-3 text-sm text-indigo-600 underline hover:text-indigo-800"
                                            >
                                                Add footer link
                                            </button>
                                        )}
                                        <InputError message={errors.footer_links} className="mt-2" />
                                    </div>
                                </section>
                            </fieldset>

                            <div className="flex items-center gap-4">
                                {!readOnly && (
                                    <PrimaryButton disabled={processing}>
                                        {processing ? 'Saving…' : 'Save branding'}
                                    </PrimaryButton>
                                )}
                                <Link
                                    href={route('admin.tenants.show', tenant.id)}
                                    className="text-sm text-gray-600 underline hover:text-gray-900"
                                >
                                    Back to tenant
                                </Link>
                            </div>
                        </form>

                        <aside className="lg:col-span-2">
                            <div className="sticky top-6 space-y-2">
                                <h3 className="text-sm font-medium uppercase tracking-wider text-gray-500">
                                    Preview
                                </h3>
                                <Preview branding={previewBranding} faviconUrl={faviconUrl} />
                                <p className="text-xs text-gray-500">
                                    Changes appear on {tenant.domain ?? 'the tenant site'} on the next page load after saving.
                                </p>
                            </div>
                        </aside>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
