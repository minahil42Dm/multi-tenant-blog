import BlogLayout from '@/Layouts/BlogLayout';
import { Head, Link } from '@inertiajs/react';

export default function Show({ tenantName, branding, post }) {
    return (
        <BlogLayout branding={branding}>
            <Head title={post.title} />

            <Link
                href={route('blog.home')}
                className="text-sm font-medium underline"
                style={{ color: branding.color }}
            >
                All posts
            </Link>

            <article className="mt-4 overflow-hidden rounded-lg bg-white shadow-sm">
                {post.cover_url && (
                    <img
                        src={post.cover_url}
                        alt=""
                        className="max-h-96 w-full object-cover"
                    />
                )}
                <div className="p-6">
                <h1 className="text-3xl font-bold" style={{ color: branding.color }}>
                    {post.title}
                </h1>
                {post.published_at && (
                    <p className="mt-2 text-sm text-gray-500">
                        {post.published_at}
                    </p>
                )}
                <div className="mt-6 whitespace-pre-wrap text-gray-900">
                    {post.body}
                </div>
                </div>
            </article>
        </BlogLayout>
    );
}
