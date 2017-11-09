<?php

namespace App\Http\Controllers\Api\V1;

use App\Bridge;
use App\Color;
use App\Events\BridgeUpdated;
use App\Exceptions\IconShouldBeSVG;
use App\Exceptions\ImageShouldBePNGOrJPG;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConvertedStoreRequest;
use App\Http\Requests\CreateConvertedIcon;
use App\Http\Requests\IconStoreRequest;
use App\Http\Requests\StoreColorRequest;
use App\Icon;
use App\IconConverted;
use App\Image;
use App\ImageConverted;
use App\Jobs\DeleteFile;
use App\Jobs\ReorderAfterDelete;
use App\Section;
use App\SectionType;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SourceFileController extends Controller {

	public function storeIcon( IconStoreRequest $request, $bridgeId ) {

		try {

			$user   = Auth::user();
			$bridge = Bridge::findOrFail( $bridgeId );
			if ( $user->id !== $bridge->user_id ) {
				throw new ModelNotFoundException();
			}

			if ( $request->file( 'icon' )->getClientMimeType() !== 'image/svg+xml' ) {
				throw new IconShouldBeSVG();
			}

			$im = new \Imagick();
			$im->setBackgroundColor( new \ImagickPixel( 'transparent' ) );
			$im->readImageBlob( file_get_contents( $request->file( 'icon' )->getRealPath() ) );
			$im->setImageFormat( 'png32' );
			$im->resizeImage( $im->getImageWidth(), $im->getImageHeight(), \Imagick::FILTER_LANCZOS, 1 );


			$filenameIcon = str_random( 40 ) . '.svg';
			$request->file( 'icon' )->storeAs( '', $filenameIcon, 'assets' );

			$filenameConverted = str_random( 40 ) . '.png';
			\Storage::disk( 'assets' )->put( $filenameConverted, $im->getImageBlob() );

			$sectionType = SectionType::where( 'name', SectionType::ICONS )->get()->first();
			$section     = Section::where( 'section_type_id', $sectionType->id )->where( 'bridge_id', $bridgeId )->get()->first();

			$icon = Icon::create( [
				'bridge_id'   => $bridgeId,
				'filename'    => $filenameIcon,
				'width_ratio' => $im->getImageWidth() / $im->getImageHeight(),
				'section_id'  => $section->id,
				'order'       => Icon::where( 'section_id', $section->id )->where( 'bridge_id', $bridgeId )->get()->count()
			] );

			$converted = IconConverted::create( [
				'icon_id'  => $icon->id,
				'filename' => $filenameConverted,
				'width'    => $im->getImageWidth(),
				'height'   => $im->getImageHeight()
			] );

			$bridge = Bridge::with( 'sections', 'icons', 'icons.converted', 'images', 'images.converted', 'fonts', 'fonts.variant', 'fonts.variant.fontFamily', 'colors' )->findOrFail( $bridgeId );
			try {
				event( new BridgeUpdated( $bridge ) );
			} catch ( \Exception $e ) {
			}

			return response()->json( [
				'bridge'        => $bridge,
				'section_types' => SectionType::all()
			] );
		} catch ( ModelNotFoundException $e ) {
			return response()->json( [
				'error' => 'Entry not found'
			] );
		} catch ( IconShouldBeSVG $e ) {
			return response()->json( [
				'error' => $e->getMessage()
			] );
		} catch ( \Exception $e ) {
			return response()->json( [
				'error' => 'Server error'
			] );
		}
	}

	public function updateIconFile( Request $request, $bridgeId, $iconId ) {
		try {

			$user   = Auth::user();
			$bridge = Bridge::findOrFail( $bridgeId );
			if ( $user->id !== $bridge->user_id ) {
				throw new ModelNotFoundException();
			}

			if ( $request->file( 'icon' )->getClientMimeType() !== 'image/svg+xml' ) {
				throw new IconShouldBeSVG();
			}

			$im = new \Imagick();
			$im->readImageBlob( file_get_contents( $request->file( 'icon' )->getRealPath() ) );
			$im->setImageFormat( 'png32' );

			$filenameIcon = str_random( 40 ) . '.svg';
			$request->file( 'icon' )->storeAs( '', $filenameIcon, 'assets' );

			$icon              = Icon::findOrFail( $iconId );
			$icon->filename    = $filenameIcon;
			$icon->width_ratio = $im->getImageWidth() / $im->getImageHeight();
			$icon->save();

			$this->updateConvertedIcons( $icon );

			$bridge = Bridge::with( 'sections', 'icons', 'icons.converted', 'images', 'images.converted', 'fonts', 'fonts.variant', 'fonts.variant.fontFamily', 'colors' )->findOrFail( $bridgeId );
			try {
				event( new BridgeUpdated( $bridge ) );
			} catch ( \Exception $e ) {
			}

			return response()->json( [
				'bridge'        => $bridge,
				'section_types' => SectionType::all()
			] );

		} catch ( ModelNotFoundException $e ) {
			return response()->json( [
				'error' => 'Entry not found'
			] );
		} catch ( \Exception $e ) {
			return response()->json( [
				'error' => 'Server error'
			] );
		}
	}

	private function updateConvertedIcons( Icon $icon ) {

		foreach ( $icon->converted as $converted ) {
			$width = (int) $converted->width;

			$im = new \Imagick();
			$im->setBackgroundColor( new \ImagickPixel( 'transparent' ) );
			$im->readImageBlob( \Storage::disk( 'assets' )->get( $icon->filename ) );
			$im->setImageFormat( 'png32' );
			$im->resizeImage( $width, $width / $icon->width_ratio, \Imagick::FILTER_LANCZOS, 1 );

			//$filenameConverted = str_random(40) . '.png';
			//$converted->filename = $filenameConverted;
			$converted->width  = $im->getImageWidth();
			$converted->height = $im->getImageHeight();
			$converted->save();
			\Storage::disk( 'assets' )->put( $converted->filename, $im->getImageBlob() );
		}
	}

	public function storeImage( Request $request, $bridgeId ) {
		try {

			$user   = Auth::user();
			$bridge = Bridge::findOrFail( $bridgeId );
			if ( $user->id !== $bridge->user_id ) {
				throw new ModelNotFoundException();
			}

			$user = Auth::user();
			if ( $user->id !== $bridge->user_id ) {
				throw new ModelNotFoundException();
			}

			$image = $request->file( 'image' );

			if ( ! ( $image->getClientMimeType() === 'image/jpeg' || $image->getClientMimeType() === 'image/png' ) ) {
				throw new ImageShouldBePNGOrJPG();
			}

			if ( $image->getClientMimeType() === 'image/jpeg' ) {
				$imageExt  = 'jpg';
				$imageType = 'jpeg';
			} else {
				$imageExt  = 'png';
				$imageType = 'png32';
			}

			$im = new \Imagick();
			$im->setBackgroundColor( new \ImagickPixel( 'transparent' ) );
			$im->readImageBlob( file_get_contents( $image->getRealPath() ) );
			$im->setImageFormat( $imageType );
			$im->resizeImage( $im->getImageWidth(), $im->getImageHeight(), \Imagick::FILTER_LANCZOS, 1 );

			$sectionType = SectionType::where( 'name', SectionType::IMAGES )->get()->first();
			$section     = Section::where( 'section_type_id', $sectionType->id )->where( 'bridge_id', $bridgeId )->get()->first();

			$filenameImage = $bridge->name . '_' . $sectionType->name . '_' . rand( 1, 9999 ) . '.' . $imageExt;
			$image->storeAs( '', $filenameImage, 'assets' );

			$filenameConverted = str_random( 40 ) . '.' . $imageExt;
			\Storage::disk( 'assets' )->put( $filenameConverted, $im->getImageBlob() );

			$image = Image::create( [
				'bridge_id'   => $bridgeId,
				'filename'    => $filenameImage,
				'width_ratio' => $im->getImageWidth() / $im->getImageHeight(),
				'section_id'  => $section->id,
				'order'       => Image::where( 'section_id', $section->id )->where( 'bridge_id', $bridgeId )->get()->count()
			] );

			$converted = ImageConverted::create( [
				'image_id' => $image->id,
				'filename' => $filenameConverted,
				'width'    => $im->getImageWidth(),
				'height'   => $im->getImageHeight()
			] );

			$bridge = Bridge::with( 'sections', 'icons', 'icons.converted', 'images', 'images.converted', 'fonts', 'fonts.variant', 'fonts.variant.fontFamily', 'colors' )->findOrFail( $bridgeId );
			try {
				event( new BridgeUpdated( $bridge ) );
			} catch ( \Exception $e ) {
			}

			return response()->json( [
				'bridge'        => $bridge,
				'section_types' => SectionType::all()
			] );
		} catch ( ModelNotFoundException $e ) {
			return response()->json( [
				'error' => 'Entry not found'
			] );
		} catch ( \Exception $e ) {
			dd( $e );

			return response()->json( [
				'error' => 'Server error'
			] );
		}
	}

	public function addIconConverted( CreateConvertedIcon $request, $bridgeId, $iconId ) {
		try {

			$user   = Auth::user();
			$bridge = Bridge::findOrFail( $bridgeId );
			if ( $user->id !== $bridge->user_id ) {
				throw new ModelNotFoundException();
			}

			$icon = Icon::findOrFail( $iconId );

			$im = new \Imagick();

			$width = (int) $request->get( 'width' );

			$im->setBackgroundColor( new \ImagickPixel( 'transparent' ) );
			$im->readImageBlob( \Storage::disk( 'assets' )->get( $icon->filename ) );
			$im->setImageFormat( 'png32' );
			$im->resizeImage( $width, $width / $icon->width_ratio, \Imagick::FILTER_LANCZOS, 1 );

			$filenameConverted = str_random( 40 ) . '.png';
			$iconConverted     = IconConverted::create( [
				'icon_id'  => $icon->id,
				'filename' => $filenameConverted,
				'width'    => $im->getImageWidth(),
				'height'   => $im->getImageHeight()
			] );
			\Storage::disk( 'assets' )->put( $filenameConverted, $im->getImageBlob() );

			$bridge = Bridge::with( 'sections', 'icons', 'icons.converted', 'images', 'images.converted', 'fonts', 'fonts.variant', 'fonts.variant.fontFamily', 'colors' )->findOrFail( $bridgeId );

			return response()->json( [
				'bridge'        => $bridge,
				'section_types' => SectionType::all()
			] );

		} catch ( ModelNotFoundException $e ) {
			return response()->json( [
				'error' => 'Entry not found'
			] );
		} catch ( \Exception $e ) {
			return response()->json( [
				'error' => 'Server error'
			] );
		}
	}

	public function addImageConverted( ConvertedStoreRequest $request, $bridgeId, $imageId ) {
		try {

			$user   = Auth::user();
			$bridge = Bridge::findOrFail( $bridgeId );
			if ( $user->id !== $bridge->user_id ) {
				throw new ModelNotFoundException();
			}

			$format = $request->get( 'format' );

			if ( $format === 'jpg' ) {
				$imageExt  = 'jpg';
				$imageType = 'jpeg';
			} else {
				$imageExt  = 'png';
				$imageType = 'png32';
			}

			$image = Image::findOrFail( $imageId );

			$im = new \Imagick();

			$width = (int) $request->get( 'width' );

			$im->setBackgroundColor( new \ImagickPixel( 'transparent' ) );
			$im->readImageBlob( \Storage::disk( 'assets' )->get( $image->filename ) );
			$im->setImageFormat( $imageType );
			$im->resizeImage( $width, $width / $image->width_ratio, \Imagick::FILTER_LANCZOS, 1 );

			$filenameConverted = str_random( 40 ) . '.' . $imageExt;
			$imageConverted    = ImageConverted::create( [
				'image_id' => $image->id,
				'filename' => $filenameConverted,
				'width'    => $im->getImageWidth(),
				'height'   => $im->getImageHeight()
			] );
			\Storage::disk( 'assets' )->put( $filenameConverted, $im->getImageBlob() );

			$bridge = Bridge::with( 'sections', 'icons', 'icons.converted', 'images', 'images.converted', 'fonts', 'fonts.variant', 'fonts.variant.fontFamily', 'colors' )->findOrFail( $bridgeId );

			return response()->json( [
				'bridge'        => $bridge,
				'section_types' => SectionType::all()
			] );

		} catch ( ModelNotFoundException $e ) {
			return response()->json( [
				'error' => 'Entry not found'
			] );
		} catch ( \Exception $e ) {
			return response()->json( [
				'error' => 'Server error'
			] );
		}
	}


	public function deleteIcon( Request $request, $bridgeId, $iconId ) {
		try {

			$user   = Auth::user();
			$bridge = Bridge::findOrFail( $bridgeId );
			if ( $user->id !== $bridge->user_id ) {
				throw new ModelNotFoundException();
			}

			$icon      = Icon::findOrFail( $iconId );
			$sectionId = $icon->section_id;
			$icon->delete();

			$icons = Icon::where( 'section_id', $sectionId )->get();

			( new ReorderAfterDelete( $icons ) )->handle();

			$bridge = Bridge::with( 'sections', 'icons', 'icons.converted', 'images', 'images.converted', 'fonts', 'fonts.variant', 'fonts.variant.fontFamily', 'colors' )->findOrFail( $bridgeId );
			try {
				event( new BridgeUpdated( $bridge ) );
			} catch ( \Exception $e ) {
			}

			return response()->json( [
				'bridge'        => $bridge,
				'section_types' => SectionType::all()
			] );
		} catch ( ModelNotFoundException $e ) {
			return response()->json( [
				'error' => 'Entry not found'
			] );
		} catch ( \Exception $e ) {
			return response()->json( [
				'error' => 'Server error'
			] );
		}
	}

	public function deleteImage( Request $request, $bridgeId, $imageId ) {
		try {

			$user   = Auth::user();
			$bridge = Bridge::findOrFail( $bridgeId );
			if ( $user->id !== $bridge->user_id ) {
				throw new ModelNotFoundException();
			}

			$image     = Image::findOrFail( $imageId );
			$sectionId = $image->section_id;
			$image->delete();

			$images = Image::where( 'section_id', $sectionId )->get();

			( new ReorderAfterDelete( $images ) )->handle();

			$bridge = Bridge::with( 'sections', 'icons', 'icons.converted', 'images', 'images.converted', 'fonts', 'fonts.variant', 'fonts.variant.fontFamily', 'colors' )->findOrFail( $bridgeId );
			try {
				event( new BridgeUpdated( $bridge ) );
			} catch ( \Exception $e ) {
			}

			return response()->json( [
				'bridge'        => $bridge,
				'section_types' => SectionType::all()
			] );
		} catch ( ModelNotFoundException $e ) {
			return response()->json( [
				'error' => 'Entry not found'
			] );
		} catch ( \Exception $e ) {
			return response()->json( [
				'error' => 'Server error'
			] );
		}
	}

	public function updateImageFile( Request $request, $bridgeId, $imageId ) {
		try {

			$user   = Auth::user();
			$bridge = Bridge::findOrFail( $bridgeId );
			if ( $user->id !== $bridge->user_id ) {
				throw new ModelNotFoundException();
			}

			$image = $request->file( 'image' );

			if ( ! ( $image->getClientMimeType() === 'image/jpeg' || $image->getClientMimeType() === 'image/png' ) ) {
				throw new ImageShouldBePNGOrJPG();
			}

			if ( $image->getClientMimeType() === 'image/jpeg' ) {
				$imageExt  = 'jpg';
				$imageType = 'jpeg';
			} else {
				$imageExt  = 'png';
				$imageType = 'png32';
			}

			$im = new \Imagick();
			$im->readImageBlob( file_get_contents( $request->file( 'image' )->getRealPath() ) );
			$im->setImageFormat( $imageType );

			$filenameIcon = str_random( 40 ) . '.' . $imageExt;
			$request->file( 'image' )->storeAs( '', $filenameIcon, 'assets' );

			$image              = Image::findOrFail( $imageId );
			$image->filename    = $filenameIcon;
			$image->width_ratio = $im->getImageWidth() / $im->getImageHeight();
			$image->save();

			$this->updateConvertedImages( $image );

			$bridge = Bridge::with( 'sections', 'icons', 'icons.converted', 'images', 'images.converted', 'fonts', 'fonts.variant', 'fonts.variant.fontFamily', 'colors' )->findOrFail( $bridgeId );
			try {
				event( new BridgeUpdated( $bridge ) );
			} catch ( \Exception $e ) {
			}

			return response()->json( [
				'bridge'        => $bridge,
				'section_types' => SectionType::all()
			] );

		} catch ( ModelNotFoundException $e ) {
			return response()->json( [
				'error' => 'Entry not found'
			] );
		} catch ( \Exception $e ) {
			return response()->json( [
				'error' => 'Server error'
			] );
		}
	}

	private function updateConvertedImages( Image $image ) {

		foreach ( $image->converted as $converted ) {
			$width = (int) $converted->width;

			$parsedString = explode( '.', $converted->filename );

			if ( end( $parsedString ) === 'png' ) {
				$imageType = 'png32';
			} else {
				$imageType = 'jpeg';
			}

			$im = new \Imagick();
			$im->setBackgroundColor( new \ImagickPixel( 'transparent' ) );
			$im->readImageBlob( \Storage::disk( 'assets' )->get( $image->filename ) );
			$im->setImageFormat( $imageType );
			$im->resizeImage( $width, $width / $image->width_ratio, \Imagick::FILTER_LANCZOS, 1 );

			$converted->width  = $im->getImageWidth();
			$converted->height = $im->getImageHeight();
			$converted->save();
			\Storage::disk( 'assets' )->put( $converted->filename, $im->getImageBlob() );
		}
	}

}
