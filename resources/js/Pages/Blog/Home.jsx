import BlogLayout from '@/Layouts/BlogLayout';
import { Head, Link } from '@inertiajs/react';

export default function Home({ tenantName, branding, posts }) {
    return (
        <BlogLayout branding={branding}>
            <Head title={tenantName} />

            <h1 className="text-3xl font-bold" style={{ color: branding.color }}>
                {tenantName}
            </h1>

            {posts.length === 0 ? (
                <p className="mt-6 text-gray-600">No published posts yet.</p>
            ) : (
                <div className="mt-8 space-y-6">
                    {posts.map((post) => (
                        <article
                            key={post.slug}
                            className="overflow-hidden rounded-lg bg-white shadow-sm"
                        >
                            {post.cover_url && (
                                <img
                                    src={post.cover_url}
                                    alt=""
                                    className="h-48 w-full object-cover"
                                />
                            )}
                            <div className="p-6">
                            <h2 className="text-xl font-semibold">
                                <Link
                                    href={route('blog.show', post.slug)}
                                    className="hover:underline"
                                    style={{ color: branding.color }}
                                >
                                    {post.title}
                                </Link>
                            </h2>
                            {post.published_at && (
                                <p className="mt-1 text-sm text-gray-500">
                                    {post.published_at}
                                </p>
                            )}
                            <p className="mt-3 text-gray-700">{post.excerpt}</p>
                            </div>
                        </article>
                    ))}
                </div>
            )}
        </BlogLayout>
    );
}
