import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { useForm } from '@inertiajs/react';

export default function Form({ post = null }) {
    const { data, setData, post: submitPost, processing, errors, transform } =
        useForm({
            title: post?.title ?? '',
            body: post?.body ?? '',
            status: post?.status ?? 'draft',
            published_at: post?.published_at ?? '',
            cover_image: null,
            remove_cover_image: false,
        });

    const submit = (event) => {
        event.preventDefault();

        if (post) {
            transform((form) => ({
                ...form,
                _method: 'put',
            }));
        }

        submitPost(
            post
                ? route('writer.posts.update', post.id)
                : route('writer.posts.store'),
            { forceFormData: true },
        );
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <div>
                <InputLabel htmlFor="title" value="Title" />
                <TextInput
                    id="title"
                    className="mt-1 block w-full"
                    value={data.title}
                    onChange={(event) => setData('title', event.target.value)}
                    required
                    isFocused
                />
                <InputError className="mt-2" message={errors.title} />
            </div>

            <div>
                <InputLabel htmlFor="body" value="Body" />
                <textarea
                    id="body"
                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    rows="10"
                    value={data.body}
                    onChange={(event) => setData('body', event.target.value)}
                    required
                />
                <InputError className="mt-2" message={errors.body} />
            </div>

            <div>
                <InputLabel htmlFor="status" value="Status" />
                <select
                    id="status"
                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    value={data.status}
                    onChange={(event) => setData('status', event.target.value)}
                >
                    <option value="draft">Draft</option>
                    <option value="scheduled">Scheduled</option>
                    <option value="published">Published</option>
                </select>
                <InputError className="mt-2" message={errors.status} />
            </div>

            <div>
                <InputLabel htmlFor="cover_image" value="Cover image" />
                {post?.cover_url && !data.remove_cover_image && (
                    <img
                        src={post.cover_url}
                        alt=""
                        className="mt-2 h-40 w-full rounded object-cover"
                    />
                )}
                <input
                    id="cover_image"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    className="mt-2 block w-full text-sm text-gray-700"
                    onChange={(event) =>
                        setData('cover_image', event.target.files[0] ?? null)
                    }
                />
                <p className="mt-1 text-sm text-gray-500">
                    Optional JPEG, PNG, or WebP up to 2 MB.
                </p>
                {post?.cover_url && (
                    <label className="mt-2 flex items-center gap-2 text-sm text-gray-700">
                        <input
                            type="checkbox"
                            checked={data.remove_cover_image}
                            onChange={(event) =>
                                setData(
                                    'remove_cover_image',
                                    event.target.checked,
                                )
                            }
                        />
                        Remove cover image
                    </label>
                )}
                <InputError className="mt-2" message={errors.cover_image} />
            </div>

            {data.status !== 'draft' && (
                <div>
                    <InputLabel
                        htmlFor="published_at"
                        value={
                            data.status === 'scheduled'
                                ? 'Schedule for'
                                : 'Published date'
                        }
                    />
                    <TextInput
                        id="published_at"
                        type="datetime-local"
                        className="mt-1 block w-full"
                        value={data.published_at}
                        onChange={(event) =>
                            setData('published_at', event.target.value)
                        }
                        required
                    />
                    {data.status === 'scheduled' && (
                        <p className="mt-1 text-sm text-gray-500">
                            Choose a future date and time.
                        </p>
                    )}
                    <InputError className="mt-2" message={errors.published_at} />
                </div>
            )}

            <PrimaryButton disabled={processing}>
                {post ? 'Save post' : 'Create post'}
            </PrimaryButton>
        </form>
    );
}
