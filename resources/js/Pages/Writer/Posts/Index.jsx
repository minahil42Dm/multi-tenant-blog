import DangerButton from '@/Components/DangerButton';
import PrimaryButton from '@/Components/PrimaryButton';
import WriterLayout from '@/Layouts/WriterLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';

export default function Index({ posts }) {
    const success = usePage().props.flash.success;

    const destroy = (post) => {
        if (confirm(`Delete “${post.title}”?`)) {
            router.delete(route('writer.posts.destroy', post.id));
        }
    };

    return (
        <WriterLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        Posts
                    </h2>
                    <Link href={route('writer.posts.create')}>
                        <PrimaryButton>New post</PrimaryButton>
                    </Link>
                </div>
            }
        >
            <Head title="Posts" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    {success && (
                        <div className="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-700">
                            {success}
                        </div>
                    )}

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        {posts.length === 0 ? (
                            <div className="p-6 text-gray-600">
                                No posts yet.
                            </div>
                        ) : (
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Title
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Status
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Date
                                        </th>
                                        <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200">
                                    {posts.map((post) => (
                                        <tr key={post.id}>
                                            <td className="px-6 py-4 text-sm text-gray-900">
                                                <div className="flex items-center gap-3">
                                                    {post.cover_url ? (
                                                        <img
                                                            src={post.cover_url}
                                                            alt=""
                                                            className="h-10 w-16 rounded object-cover"
                                                        />
                                                    ) : (
                                                        <span className="h-10 w-16 rounded bg-gray-100" />
                                                    )}
                                                    <span>{post.title}</span>
                                                </div>
                                            </td>
                                            <td className="px-6 py-4 text-sm text-gray-600">
                                                {post.status}
                                            </td>
                                            <td className="px-6 py-4 text-sm text-gray-600">
                                                {post.status === 'scheduled' &&
                                                post.published_at
                                                    ? `Scheduled ${post.published_at}`
                                                    : (post.published_at ?? '—')}
                                            </td>
                                            <td className="space-x-3 px-6 py-4 text-right text-sm">
                                                <Link
                                                    href={route(
                                                        'writer.posts.show',
                                                        post.id,
                                                    )}
                                                    className="text-gray-600 underline"
                                                >
                                                    View
                                                </Link>
                                                {post.can_manage && (
                                                    <>
                                                        <Link
                                                            href={route(
                                                                'writer.posts.edit',
                                                                post.id,
                                                            )}
                                                            className="text-gray-600 underline"
                                                        >
                                                            Edit
                                                        </Link>
                                                        <DangerButton
                                                            type="button"
                                                            onClick={() =>
                                                                destroy(post)
                                                            }
                                                        >
                                                            Delete
                                                        </DangerButton>
                                                    </>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>
            </div>
        </WriterLayout>
    );
}
