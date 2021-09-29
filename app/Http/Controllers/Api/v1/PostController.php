<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Post;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Storage;

class PostController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $posts = Post::query()->paginate(config('misc.page.size'));
        return response()->json($posts);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $this->validate($request, [
            'message' => 'required|string|max:191',
            'media' => 'nullable|array|max:' . config('misc.post.media.max'),
            'poll' => 'nullable|array|min:2|max:' . config('misc.post.poll.max'),
            'media.*' => 'integer',
            'poll.*' => 'string|max:191',
            'expires' => 'nullable|integer|min:1|max:' . config('misc.post.expire.max'),
            'schedule' => 'nullable|date',
            'price' => 'nullable|integer'
        ]);

        $user = auth()->user();
        $data = $request->only(['message', 'expires']);

        $schedule = $request->input('schedule');
        if ($schedule) {
            $schedule = new Carbon($schedule, 'UTC');
            if (!$schedule->copy()->subMinutes(15)->isFuture()) {
                return response()->json([
                    'message' => '',
                    'errors' => [
                        'schedule' => __('errors.schedule-must-be-in-future')
                    ]
                ], 422);
            }
        }

        $price = $request->input('price');
        if ($price) {
            // TODO: actually check if this account is free
            if (!config('misc.pricing.allow_paid_posts_for_paid_accounts') && true) {
                return response()->json([
                    'message' => '',
                    'errors' => [
                        'price' => __('errors.only-free-can-paid-post')
                    ]
                ], 422);
            }
        }

        $post = $user->posts()->create([
            'message' => $request->input('message'),
            'expires' => $request->input('expires'),
            'schedule' => $schedule,
            'price' => $price,
        ]);

        $media = $request->input('media');
        if ($media) {
            $media = $user->media()->whereIn('id', $media)->get();
            foreach ($media as $med) {
                $med->publish();
            }
            $post->media()->sync($media);
        }

        foreach ($request->input('poll', []) as $option) {
            $post->poll()->create([
                'option' => $option
            ]);
        }

        $post->refresh()->load(['media', 'poll']);
        return response()->json($post);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Post  $post
     * @return \Illuminate\Http\Response
     */
    public function show(Post $post)
    {
        return response()->json($post);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Post  $post
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Post $post)
    {
        // TODO: allow only owner
        $this->validate($request, [
            'message' => 'required|string|max:191',
            'media' => 'nullable|array',
            'poll' => 'nullable|array|min:2',
            'media.*' => 'integer',
            'poll.*' => 'string|max:191',
            'expires' => 'nullable|integer|min:1|max:30',
            'schedule' => 'nullable|date',
            'price' => 'nullable|integer'
        ]);

        $user = auth()->user();
        $data = $request->only(['message', 'expires']);

        $schedule = $request->input('schedule');
        if ($schedule) {
            $schedule = new Carbon($schedule, 'UTC');
            if (!$schedule->copy()->subMinutes(15)->isFuture()) {
                return response()->json([
                    'message' => '',
                    'errors' => [
                        'schedule' => __('errors.schedule-must-be-in-future')
                    ]
                ], 422);
            }
        }

        $price = $request->input('price');
        if ($price) {
            // TODO: actually check if this account is free
            if (!config('misc.pricing.allow_paid_posts_for_paid_accounts') && true) {
                return response()->json([
                    'message' => '',
                    'errors' => [
                        'price' => __('errors.only-free-can-paid-post')
                    ]
                ], 422);
            }
        }

        $post->fill([
            'message' => $request->input('message'),
            'expires' => $request->input('expires'),
            'schedule' => $schedule,
            'price' => $price,
        ]);
        $post->save();

        $media = $request->input('media');
        if ($media) {
            $media = $user->media()->whereIn('id', $media)->get();
            $post->media()->sync($media);
        }

        $ex_poll = $post->poll->pluck('option')->toArray();
        $in_poll = $request->input('poll', []);
        foreach ($post->poll as $poll) {
            if (!in_array($poll->option, $in_poll)) {
                $poll->delete();
            }
        }

        foreach ($in_poll as $option) {
            if (!in_array($option, $ex_poll)) {
                $post->poll()->create([
                    'option' => $option
                ]);
            }
        }

        $post->refresh()->load(['media', 'poll']);
        return response()->json($post);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Post  $post
     * @return \Illuminate\Http\Response
     */
    public function destroy(Post $post)
    {
        if ($post->user_id == auth()->user()->id) {
            $post->delete();
        }
        return response()->json(['status' => true]);
    }

    public function like(Post $post, Request $request)
    {
        $user = auth()->user();
        $res = $post->likes()->toggle([$user->id]);

        $status = count($res['attached']) > 0;
        if ($status) {
            $post->user->notifications()->firstOrCreate([
                'type' => Notification::TYPE_LIKE,
                'info' => [
                    'user_id' => $user->id,
                    'post_id' => $post->id
                ]
            ]);
        }

        return response()->json(['status' => $status]);
    }
}