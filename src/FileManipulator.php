<?php

namespace Spatie\MediaLibrary;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Support\Facades\File;
use Spatie\Glide\GlideImage;
use Spatie\MediaLibrary\Conversion\Conversion;
use Spatie\MediaLibrary\Conversion\ConversionCollection;
use Spatie\MediaLibrary\Conversion\ConversionCollectionFactory;
use Spatie\MediaLibrary\Events\ConversionHasBeenCompleted;
use Spatie\MediaLibrary\Helpers\File as MediaLibraryFileHelper;
use Spatie\MediaLibrary\Jobs\PerformConversions;
use Spatie\PdfToImage\Pdf;

class FileManipulator
{
    use DispatchesJobs;

    /**
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    protected $events;

    public function __construct(Dispatcher $events)
    {
        $this->events = $events;
    }

    /**
     * Create all derived files for the given media.
     *
     * @param \Spatie\MediaLibrary\Media $media
     */
    public function createDerivedFiles(Media $media)
    {
        if ($media->type == Media::TYPE_OTHER) {
            return;
        }

        if (($media->type == Media::TYPE_PDF || $media->type == Media::TYPE_WORD) && !class_exists('Imagick')) {
            return;
        }

        if ($media->type == Media::TYPE_VIDEO) {
            $tempDirectory = $this->createTempDirectory();

            $copiedOriginalFile = $tempDirectory.'/'.str_random(16).'.'.$media->extension;

            app(Filesystem::class)->copyFromMediaLibrary($media, $copiedOriginalFile);

            if(!file_exists($tempDirectory.'/thumb.mp4')){
              $copiedOriginalFile = $this->ToCompressedMP4($copiedOriginalFile);
              app(Filesystem::class)->copyToMediaLibrary($copiedOriginalFile, $media, true, 'thumb.mp4');
            }
            $copiedOriginalFile = $this->ToCompressedMP4($copiedOriginalFile);
            return;
        }

        $profileCollection = ConversionCollectionFactory::createForMedia($media);

        $this->performConversions($profileCollection->getNonQueuedConversions($media->collection_name), $media);

        $queuedConversions = $profileCollection->getQueuedConversions($media->collection_name);

        if (count($queuedConversions)) {
            $this->dispatchQueuedConversions($media, $queuedConversions);
        }
    }

    /**
     * Perform the given conversions for the given media.
     *
     * @param \Spatie\MediaLibrary\Conversion\ConversionCollection $conversions
     * @param \Spatie\MediaLibrary\Media                           $media
     */
    public function performConversions(ConversionCollection $conversions, Media $media)
    {
        $tempDirectory = $this->createTempDirectory();

        $copiedOriginalFile = $tempDirectory.'/'.str_random(16).'.'.$media->extension;

        app(Filesystem::class)->copyFromMediaLibrary($media, $copiedOriginalFile);

        if ($media->type == Media::TYPE_PDF) {
            $copiedOriginalFile = $this->convertPDFToImage($copiedOriginalFile);
        }

        if ($media->type == Media::TYPE_WORD) {
            if(!file_exists($tempDirectory.'/thumb.pdf')){
              $copiedOriginalFile = $this->convertWORDToPDF($copiedOriginalFile);
              app(Filesystem::class)->copyToMediaLibrary($copiedOriginalFile, $media, true, 'thumb.pdf');
            }
            $copiedOriginalFile = $this->convertPDFToImage($copiedOriginalFile);
        }

        foreach ($conversions as $conversion) {
            $conversionResult = $this->performConversion($media, $conversion, $copiedOriginalFile);

            $renamedFile = MediaLibraryFileHelper::renameInDirectory($conversionResult, $conversion->getName().'.'.
                $conversion->getResultExtension(pathinfo($copiedOriginalFile, PATHINFO_EXTENSION)));

            app(Filesystem::class)->copyToMediaLibrary($renamedFile, $media, true);

            $this->events->fire(new ConversionHasBeenCompleted($media, $conversion));
        }

        File::deleteDirectory($tempDirectory);
    }

    /**
     * Perform the conversion.
     *
     * @param \Spatie\MediaLibrary\Media $media
     * @param Conversion                 $conversion
     * @param string                     $copiedOriginalFile
     *
     * @return string
     */
    public function performConversion(Media $media, Conversion $conversion, $copiedOriginalFile)
    {
        $conversionTempFile = pathinfo($copiedOriginalFile, PATHINFO_DIRNAME).'/'.string()->random(16).
            $conversion->getName().'.'.$media->extension;

        File::copy($copiedOriginalFile, $conversionTempFile);

        foreach ($conversion->getManipulations() as $manipulation) {
            (new GlideImage())
                ->load($conversionTempFile, $manipulation)
                ->useAbsoluteSourceFilePath()
                ->save($conversionTempFile);
        }

        return $conversionTempFile;
    }

    /**
     * Create a directory to store some working files.
     *
     * @return string
     */
    public function createTempDirectory()
    {
        $tempDirectory = storage_path('medialibrary/temp/'.str_random(16));

        File::makeDirectory($tempDirectory, 493, true);

        return $tempDirectory;
    }

    /**
     * @param string $pdfFile
     *
     * @return string
     */
    protected function convertPDFToImage($pdfFile)
    {
        $imageFile = string($pdfFile)->pop('.').'.jpg';

        (new Pdf($pdfFile))->saveImage($imageFile);

        return $imageFile;
    }

    /**
     * @param string $wordFile
     *
     * @return string
     */
    protected function convertWordToPDF($wordFile)
    {
        $pdfFile = string($wordFile)->pop('.').'.pdf';
        $file_name_fpath = realpath($wordFile);
        exec('curl --form file=@'.$file_name_fpath.' http://'.config('laravel-medialibrary.unoconv_url').' > '.$pdfFile . ' 2> /dev/null');
        return $pdfFile;
    }

    /**
     * @param string $videoFile
     *
     * @param string
     */
     protected function ToCompressedMP4($videoFile)
     {
         $mp4File = string($videoFile)->pop('.').'.mp4';
         $file_name_fpath = realpath($videoFile);
         shell_exec('ffmpeg -y -i '.$videoFile.' '.$mp4File. ' > /dev/null 2> /dev/null');
         return $mp4File;
     }

    /**
     * Dispatch the given conversions.
     *
     * @param Media                $media
     * @param ConversionCollection $queuedConversions
     */
    protected function dispatchQueuedConversions(Media $media, ConversionCollection $queuedConversions)
    {
        $job = new PerformConversions($queuedConversions, $media);

        $customQueue = config('laravel-medialibrary.queue_name');

        if ($customQueue != '') {
            $job->onQueue($customQueue);
        }

        $this->dispatch($job);
    }
}
