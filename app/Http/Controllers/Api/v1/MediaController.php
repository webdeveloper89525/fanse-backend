<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Media;
use Illuminate\Http\Request;
use FFMpeg;
use Log;
use Storage;

class MediaController extends Controller
{

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        set_time_limit(0);

        $this->validate($request, [
            'media' => 'required|file|mimes:' . config('misc.media.mimes') . '|max:' . config('misc.media.maxsize')
        ]);

        $user = auth()->user();

        // video
        $file = $request->file('media');
        $mime = $file->getMimeType();

        $type = null;
        $media = null;

        if (strstr($mime, 'video') !== false) {
            $type = Media::TYPE_VIDEO;
        } else if (strstr($mime, 'audio') !== false) {
            $type = Media::TYPE_AUDIO;
        } else if (strstr($mime, 'image') !== false) {
            $type = Media::TYPE_IMAGE;
        }

        if ($type !== null) {
            $media = $user->media()->create([
                'type' => $type,
                'extension' => $file->extension()
            ]);
            if ($type == Media::TYPE_VIDEO) {
                $file->storeAs('tmp', $media->hash . '/media.' . $file->extension());
                $mediaOpener = FFMpeg::open('tmp/' . $media->hash . '/media.' . $file->extension());
                $durationInSeconds = $mediaOpener->getDurationInSeconds();

                $num = 6;
                for ($i = 0; $i < $num; $i++) {
                    try {
                        $tstamp = round(($durationInSeconds / $num) * $i);
                        if ($tstamp < 1) $tstamp = 1;
                        if ($tstamp > $durationInSeconds - 1) $tstamp = $durationInSeconds - 1;
                        $mediaOpener = $mediaOpener->getFrameFromSeconds($tstamp)
                            ->export()
                            ->save("tmp/" . $media->hash . "/thumb_{$i}.png");
                    } catch (\Exception $e) {
                        //Log::debug($e->getMessage());
                        $mediaOpener = FFMpeg::open('tmp/' . $media->hash . '/media.' . $file->extension());
                    }
                }
            } else {
                $file->storeAs('tmp/', $media->hash . '/media.' . $file->extension());
            }

            $media->append(['thumbs']);
        }


        return response()->json($media);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Media  $media
     * @return \Illuminate\Http\Response
     */
    public function destroy(Media $media)
    {
        $this->authorize('delete', $media);
        $media->delete();
        return response()->json(['status' => true]);
    }
}
