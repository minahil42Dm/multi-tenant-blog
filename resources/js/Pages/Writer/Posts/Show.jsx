import DangerButton from '@/Components/DangerButton';
import WriterLayout from '@/Layouts/WriterLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';

export default function Show({ post }) {
    const success = usePage().props.flash.success;

    const destroy = () => {
        if (confirm(`Delete “${post.title}”?`)) {
            router.delete(route('writer.posts.destroy', post.id));
        }
    };

    return (
        <WriterLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        {post.title}
                    </h2>
                    {post.can_manage && (
                        <div className="space-x-3">
                            <Link
                                href={route('writer.posts.edit', post.id)}
                                className="text-sm text-gray-600 underline"
                            >
                                Edit
                            </Link>
                            <DangerButton type="button" onClick={destroy}>
                                Delete
                            </DangerButton>
                        </div>
                    )}
                </div>
            }
        >
            <Head title={post.title} />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                    {success && (
                        <div className="rounded-md bg-green-50 p-4 text-sm text-green-700">
                            {success}
                        </div>
                    )}

                    <div className="bg-white p-6 shadow-sm sm:rounded-lg">
                        {post.cover_url && (
                            <img
                                src={post.cover_url}
                                alt=""
                                className="mb-4 max-h-80 w-full rounded object-cover"
                            />
                        )}
                        <p className="text-sm text-gray-500">
                            {post.status === 'scheduled' &&
                            post.published_at_label
                                ? `Scheduled for ${post.published_at_label}`
                                : post.status}
                            {post.status !== 'scheduled' &&
                            post.published_at_label
                                ? ` · ${post.published_at_label}`
                                : ''}
                            {post.author ? ` · ${post.author}` : ''}
                        </p>
                        <div className="mt-4 whitespace-pre-wrap text-gray-900">
                            {post.body}
                        </div>
                    </div>
                </div>
            </div>
        </WriterLayout>
    );
}
