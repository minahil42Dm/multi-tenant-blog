import { usePage } from '@inertiajs/react';

export default function FlashMessages() {
    const { flash } = usePage().props;

    return (
        <>
            {flash?.success && (
                <p className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">
                    {flash.success}
                </p>
            )}
            {flash?.error && (
                <p className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
                    {flash.error}
                </p>
            )}
        </>
    );
}
