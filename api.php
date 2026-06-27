'use client';

import React, { useState, useRef, useEffect, useCallback } from 'react';
import { Play, Pause, Square, FileText, X, Clock, Music2 } from 'lucide-react';
import { useQuery, useMutation } from '@tanstack/react-query';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ScrollArea } from '@/components/ui/scroll-area';
import { cn } from '@/lib/utils';

interface RadioMetadata {
  artist: string;
  title: string;
  error?: string;
}

interface AlbumArt {
  artworkUrl: string | null;
}

interface LyricsData {
  lyrics: string | null;
}

interface Song {
  id: number;
  artist: string;
  title: string;
  artwork_url: string | null;
  played_at: string;
}

// Constantes de módulo — fora do componente para não causar re-renders
const LOGO_URL =
  'https://dtvoeevhaseb5.cloudfront.net/user-uploads/3a74de9b-488f-471c-9c4a-fe833ea9aad4.jpg';

const STREAM_URLS = [
  'https://server16.srvsh.com.br:8620/stream/',
  '/radio-stream',
  'https://server16.srvsh.com.br:8620/stream',
  'https://server16.srvsh.com.br:8620/',
  'https://server16.srvsh.com.br:8620',
  '/api/stream',
];

// Componente separado para formatar o horário no cliente (evita erro de hidratação)
function SongTime({ playedAt }: { playedAt: string }) {
  const [timeStr, setTimeStr] = useState('');
  useEffect(() => {
    setTimeStr(
      new Date(playedAt).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
    );
  }, [playedAt]);
  return <span className="text-[10px] text-white/60 tabular-nums">{timeStr}</span>;
}

export default function RadioPlayerPage() {
  const [isPlaying, setIsPlaying] = useState(false);
  const [audioError, setAudioError] = useState<string | null>(null);
  const [isConnecting, setIsConnecting] = useState(false);
  const [showLyrics, setShowLyrics] = useState(false);
  const [showHistory, setShowHistory] = useState(false);
  const audioRef = useRef<HTMLAudioElement | null>(null);
  const lastSavedSong = useRef<string>('');
  const lastArtworkKey = useRef<string>('');

  // Create audio element programmatically on client side
  useEffect(() => {
    const audio = new Audio();
    audio.preload = 'none';
    audio.onplaying = () => {
      setAudioError(null);
      setIsPlaying(true);
    };
    audio.onpause = () => setIsPlaying(false);
    audioRef.current = audio;
    return () => {
      audio.pause();
      audio.src = '';
      audioRef.current = null;
    };
  }, []);

  // Tenta tocar uma URL específica, retorna true se conseguiu
  const tryPlayUrl = (audio: HTMLAudioElement, url: string): Promise<boolean> => {
    return new Promise((resolve) => {
      const onPlaying = () => {
        cleanup();
        resolve(true);
      };
      const onError = () => {
        cleanup();
        resolve(false);
      };
      const cleanup = () => {
        audio.removeEventListener('playing', onPlaying);
        audio.removeEventListener('error', onError);
      };
      audio.addEventListener('playing', onPlaying, { once: true });
      audio.addEventListener('error', onError, { once: true });
      audio.src = url;
      audio.load();
      audio.play().catch(() => {
        cleanup();
        resolve(false);
      });
    });
  };

  const togglePlay = useCallback(async () => {
    const audio = audioRef.current;
    if (!audio) return;
    setAudioError(null);

    if (isPlaying) {
      audio.pause();
      return;
    }

    setIsConnecting(true);

    // Tenta cada URL até uma funcionar
    for (const url of STREAM_URLS) {
      console.log(`Tentando stream: ${url}`);
      const success = await tryPlayUrl(audio, url);
      if (success) {
        console.log(`✅ Stream conectado: ${url}`);
        setIsConnecting(false);
        return;
      }
      console.warn(`❌ Falhou: ${url}`);
    }

    // Nenhuma URL funcionou
    setIsConnecting(false);
    setAudioError('Não foi possível conectar à rádio. Verifique sua conexão e tente novamente.');
    setIsPlaying(false);
  }, [isPlaying]);

  const stopPlayback = useCallback(() => {
    const audio = audioRef.current;
    if (audio) {
      audio.pause();
      audio.src = '';
      setIsPlaying(false);
      setAudioError(null);
    }
  }, []);

  const { data: metadata } = useQuery<RadioMetadata>({
    queryKey: ['radio-metadata'],
    queryFn: async () => {
      const res = await fetch('/api/radio-metadata');
      if (!res.ok) throw new Error('Failed to fetch metadata');
      return res.json();
    },
    refetchInterval: 15000,
  });

  const { data: artworkData } = useQuery<AlbumArt>({
    queryKey: ['album-art', metadata?.artist, metadata?.title],
    queryFn: async () => {
      if (!metadata?.artist || !metadata?.title) return { artworkUrl: null };
      const res = await fetch(
        `/api/album-art?artist=${encodeURIComponent(metadata.artist)}&title=${encodeURIComponent(metadata.title)}`
      );
      if (!res.ok) return { artworkUrl: null };
      return res.json();
    },
    enabled: !!metadata?.artist && !!metadata?.title,
  });

  // ── Media Session API — tela de bloqueio e controles do sistema ──
  useEffect(() => {
    if (typeof navigator === 'undefined' || !('mediaSession' in navigator)) return;

    const artwork = artworkData?.artworkUrl
      ? [{ src: artworkData.artworkUrl, sizes: '600x600', type: 'image/jpeg' }]
      : [{ src: LOGO_URL, sizes: '300x300', type: 'image/jpeg' }];

    navigator.mediaSession.metadata = new MediaMetadata({
      title: metadata?.title || 'Ao Vivo',
      artist: metadata?.artist || 'Rádio Temas de Novelas',
      album: 'Rádio Temas de Novelas',
      artwork,
    });

    // Handlers usam refs para evitar stale closures
    navigator.mediaSession.setActionHandler('play', () => {
      const audio = audioRef.current;
      if (audio && audio.src) audio.play().catch(() => {});
    });
    navigator.mediaSession.setActionHandler('pause', () => {
      const audio = audioRef.current;
      if (audio) audio.pause();
    });
    navigator.mediaSession.setActionHandler('stop', () => {
      const audio = audioRef.current;
      if (audio) {
        audio.pause();
        audio.src = '';
        setIsPlaying(false);
      }
    });
  }, [metadata?.title, metadata?.artist, artworkData?.artworkUrl]);

  // Sincroniza o estado de reprodução na tela de bloqueio
  useEffect(() => {
    if (typeof navigator === 'undefined' || !('mediaSession' in navigator)) return;
    navigator.mediaSession.playbackState = isPlaying ? 'playing' : 'paused';
  }, [isPlaying]);

  const { data: lyricsData, isFetching: lyricsLoading } = useQuery<LyricsData>({
    queryKey: ['lyrics', metadata?.artist, metadata?.title],
    queryFn: async () => {
      if (!metadata?.artist || !metadata?.title) return { lyrics: null };
      const res = await fetch(
        `/api/lyrics?artist=${encodeURIComponent(metadata.artist)}&title=${encodeURIComponent(metadata.title)}`
      );
      if (!res.ok) return { lyrics: null };
      return res.json();
    },
    enabled: !!metadata?.artist && !!metadata?.title,
    staleTime: 5 * 60 * 1000,
  });

  const { data: historyData, refetch: refetchHistory } = useQuery<{ songs: Song[] }>({
    queryKey: ['recently-played'],
    queryFn: async () => {
      const res = await fetch('/api/recently-played');
      if (!res.ok) return { songs: [] };
      return res.json();
    },
    refetchInterval: showHistory ? 30000 : false,
    enabled: showHistory,
  });

  const saveSongMutation = useMutation({
    mutationFn: async (song: { artist: string; title: string; artwork_url: string | null }) => {
      await fetch('/api/recently-played', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(song),
      });
    },
    onSuccess: () => {
      refetchHistory();
    },
  });

  // Salva a música quando o metadado muda (sem esperar capa)
  useEffect(() => {
    if (!metadata?.artist || !metadata?.title) return;
    const key = `${metadata.artist}||${metadata.title}`;
    if (lastSavedSong.current === key) return;
    lastSavedSong.current = key;
    saveSongMutation.mutate({
      artist: metadata.artist,
      title: metadata.title,
      artwork_url: null,
    });
  }, [metadata?.artist, metadata?.title, saveSongMutation]);

  // Quando a capa carrega, atualiza o registro no banco via PATCH
  const updateArtworkMutation = useMutation({
    mutationFn: async (data: { artist: string; title: string; artwork_url: string }) => {
      await fetch('/api/recently-played', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
      });
    },
    onSuccess: () => {
      refetchHistory();
    },
  });

  useEffect(() => {
    if (!metadata?.artist || !metadata?.title || !artworkData?.artworkUrl) return;
    const key = `${metadata.artist}||${metadata.title}`;
    if (lastArtworkKey.current === key) return;
    lastArtworkKey.current = key;
    updateArtworkMutation.mutate({
      artist: metadata.artist,
      title: metadata.title,
      artwork_url: artworkData.artworkUrl,
    });
  }, [artworkData?.artworkUrl, metadata?.artist, metadata?.title, updateArtworkMutation]);

  const handleShowHistory = () => {
    setShowLyrics(false);
    setShowHistory((v) => {
      if (!v) refetchHistory();
      return !v;
    });
  };

  const handleShowLyrics = () => {
    setShowHistory(false);
    setShowLyrics((v) => !v);
  };

  const lyricsLines = lyricsData?.lyrics ? lyricsData.lyrics.split('\n') : [];
  const songs = historyData?.songs ?? [];

  return (
    // FIX #4: Wrap in a mobile-proportioned container — max-width 420px, centered
    <div className="min-h-screen bg-gradient-to-b from-[#1a0a0f] to-[#2d0d1a] font-inter flex flex-col items-center justify-start py-8 px-4">
      {/* FIX #4: Constrain entire content to mobile width */}
      <div className="w-full max-w-[420px] flex flex-col items-center">
        {/* Header Section */}
        <div className="mb-8 text-center w-full">
          <div className="w-36 h-36 mx-auto mb-4 rounded-2xl overflow-hidden shadow-2xl bg-white p-2">
            <img
              src={LOGO_URL}
              alt="Rádio Temas de Novelas"
              className="w-full h-full object-contain"
            />
          </div>
          <div className="inline-flex items-center gap-2 px-3 py-1 bg-white/10 border border-white/20 rounded-full text-xs font-medium text-white/80 mb-3 backdrop-blur-sm">
            <span
              style={{ animation: 'pulse 1.5s ease-in-out infinite' }}
              className="w-1.5 h-1.5 bg-[#22C55E] rounded-full"
            />
            ESTAÇÃO AO VIVO
          </div>
          <h1 className="text-2xl font-bold text-white tracking-tight mb-1 drop-shadow-lg">
            Rádio Temas de Novelas
          </h1>
          <p className="text-white/60 text-sm">A trilha sonora da sua vida!</p>
        </div>

        {/* Main Player Card */}
        <Card className="w-full bg-white/5 backdrop-blur-md border border-white/10 rounded-2xl overflow-hidden shadow-2xl">
          <CardContent className="p-0">
            {/* Cover Art */}
            <div className="relative aspect-square bg-[#1a0a0f]/60 flex items-center justify-center group overflow-hidden">
              {artworkData?.artworkUrl ? (
                <img
                  src={artworkData.artworkUrl}
                  alt="Capa do álbum"
                  className={cn(
                    'w-full h-full object-cover transition-transform duration-700',
                    isPlaying ? 'scale-105' : 'scale-100'
                  )}
                />
              ) : (
                <div className="flex flex-col items-center gap-4">
                  <div className="w-28 h-28 rounded-xl overflow-hidden bg-white p-2 opacity-70">
                    <img
                      src={LOGO_URL}
                      alt="Rádio Temas de Novelas"
                      className="w-full h-full object-contain"
                    />
                  </div>
                  <p className="text-xs font-medium uppercase tracking-widest text-white/40">
                    Buscando Capa...
                  </p>
                </div>
              )}
              <div className="absolute inset-0 bg-gradient-to-t from-black/40 to-transparent opacity-0 group-hover:opacity-100 transition-opacity" />
            </div>

            {/* Info & Controls */}
            <div className="p-6">
              {/* FIX #3: Centered title and artist */}
              <div className="mb-6 text-center">
                <div className="inline-flex items-center gap-1.5 px-2 py-0.5 bg-[#e91e63]/20 text-[#f48fb1] rounded-full text-[10px] font-semibold uppercase tracking-wider mb-3 border border-[#e91e63]/30">
                  Tocando Agora
                </div>
                <h2 className="text-lg font-semibold text-white truncate mb-1 text-center">
                  {metadata?.title || 'Carregando...'}
                </h2>
                <p className="text-white/60 text-sm truncate text-center">
                  {metadata?.artist || 'Rádio Temas de Novelas'}
                </p>
              </div>

              {/* Live Bar */}
              <div className="mb-6 flex items-center gap-3">
                <div className="flex-1 h-1.5 bg-white/10 rounded-full overflow-hidden">
                  {isPlaying && (
                    <div
                      className="h-full w-full"
                      style={{
                        backgroundSize: '40px 40px',
                        backgroundImage:
                          'linear-gradient(45deg, #e91e63 25%, #f06292 25%, #f06292 50%, #e91e63 50%, #e91e63 75%, #f06292 75%, #f06292)',
                        animation: 'progress 2s linear infinite',
                      }}
                    />
                  )}
                </div>
                <span className="text-[10px] font-bold text-white/50 tabular-nums">LIVE</span>
              </div>

              {/* Error Message */}
              {audioError && (
                <p className="text-center text-[#f48fb1] text-xs mb-4">{audioError}</p>
              )}

              {/* Control Buttons — 4 buttons */}
              <div className="flex items-center justify-center gap-4">
                <Button
                  variant="ghost"
                  size="icon"
                  onClick={stopPlayback}
                  className="w-12 h-12 rounded-full border border-white/20 text-white/60 hover:bg-white/10 hover:text-white"
                >
                  <Square size={20} fill="currentColor" />
                </Button>

                <Button
                  size="icon"
                  onClick={togglePlay}
                  disabled={isConnecting}
                  className="w-20 h-20 rounded-full bg-[#e91e63] text-white hover:bg-[#c2185b] shadow-lg border-0 disabled:opacity-70"
                  style={{ boxShadow: '0 0 32px rgba(233,30,99,0.4)' }}
                >
                  {isConnecting ? (
                    <span className="text-white text-lg font-bold">...</span>
                  ) : isPlaying ? (
                    <Pause size={32} fill="currentColor" />
                  ) : (
                    <Play size={32} fill="currentColor" style={{ marginLeft: 4 }} />
                  )}
                </Button>

                {/* Lyrics Button */}
                <Button
                  variant="ghost"
                  size="icon"
                  onClick={handleShowLyrics}
                  className={cn(
                    'w-12 h-12 rounded-full border transition-all',
                    showLyrics
                      ? 'border-[#e91e63]/60 bg-[#e91e63]/20 text-[#f48fb1]'
                      : 'border-white/20 text-white/60 hover:bg-white/10 hover:text-white'
                  )}
                  title="Ver letra da música"
                >
                  <FileText size={20} />
                </Button>

                {/* History Button */}
                <Button
                  variant="ghost"
                  size="icon"
                  onClick={handleShowHistory}
                  className={cn(
                    'w-12 h-12 rounded-full border transition-all',
                    showHistory
                      ? 'border-[#e91e63]/60 bg-[#e91e63]/20 text-[#f48fb1]'
                      : 'border-white/20 text-white/60 hover:bg-white/10 hover:text-white'
                  )}
                  title="Últimas músicas tocadas"
                >
                  <Clock size={20} />
                </Button>
              </div>
            </div>

            {/* Lyrics Panel */}
            {showLyrics && (
              <div className="border-t border-white/10 bg-black/30 backdrop-blur-sm">
                <div className="flex items-center justify-between px-6 py-4 border-b border-white/5">
                  <div>
                    <p className="text-[10px] font-bold uppercase tracking-widest text-[#f48fb1] mb-0.5">
                      Letra da Música
                    </p>
                    <p className="text-white/70 text-sm font-semibold truncate max-w-[240px]">
                      {metadata?.title || '—'}
                    </p>
                  </div>
                  <Button
                    variant="ghost"
                    size="icon"
                    onClick={() => setShowLyrics(false)}
                    className="w-8 h-8 rounded-full text-white/40 hover:text-white hover:bg-white/10"
                  >
                    <X size={16} />
                  </Button>
                </div>
                <ScrollArea className="h-72 px-6 py-4">
                  {lyricsLoading ? (
                    <div className="flex flex-col gap-2 pt-4">
                      {[...Array(6)].map((_, i) => (
                        <div
                          key={i}
                          className="h-3 bg-white/10 rounded-full"
                          style={{ width: `${60 + (i % 3) * 15}%` }}
                        />
                      ))}
                    </div>
                  ) : lyricsLines.length > 0 ? (
                    <div className="space-y-1 pb-4">
                      {lyricsLines.map((line, i) => (
                        <p key={i} className="text-white/80 text-sm leading-relaxed">
                          {line || '\u00A0'}
                        </p>
                      ))}
                    </div>
                  ) : (
                    <div className="flex flex-col items-center justify-center h-full gap-3 text-center py-8">
                      <FileText size={32} className="text-white/20" />
                      <p className="text-white/40 text-sm">
                        Letra não encontrada para esta música.
                      </p>
                      <p className="text-white/25 text-xs">Tente novamente na próxima faixa!</p>
                    </div>
                  )}
                </ScrollArea>
              </div>
            )}

            {/* Recently Played Panel */}
            {showHistory && (
              <div className="border-t border-white/10 bg-black/30 backdrop-blur-sm">
                <div className="flex items-center justify-between px-6 py-4 border-b border-white/5">
                  <div className="flex items-center gap-2">
                    <Clock size={14} className="text-[#f48fb1]" />
                    <p className="text-[10px] font-bold uppercase tracking-widest text-[#f48fb1]">
                      Últimas Tocadas
                    </p>
                  </div>
                  <Button
                    variant="ghost"
                    size="icon"
                    onClick={() => setShowHistory(false)}
                    className="w-8 h-8 rounded-full text-white/40 hover:text-white hover:bg-white/10"
                  >
                    <X size={16} />
                  </Button>
                </div>
                <ScrollArea className="h-72">
                  {songs.length === 0 ? (
                    <div className="flex flex-col items-center justify-center h-full gap-3 text-center py-8">
                      <Music2 size={32} className="text-white/20" />
                      <p className="text-white/40 text-sm">Nenhuma música registrada ainda.</p>
                      <p className="text-white/25 text-xs">Dê play e as músicas aparecerão aqui!</p>
                    </div>
                  ) : (
                    <div className="py-2">
                      {songs.map((song, idx) => (
                        <div
                          key={song.id}
                          className={cn(
                            'flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-white/5',
                            idx === 0 && 'bg-[#e91e63]/10'
                          )}
                        >
                          <div className="w-14 h-14 rounded-xl overflow-hidden bg-white/10 flex-shrink-0 flex items-center justify-center">
                            {song.artwork_url ? (
                              <img
                                src={song.artwork_url}
                                alt={song.title}
                                className="w-full h-full object-cover"
                              />
                            ) : (
                              <Music2 size={20} className="text-white/30" />
                            )}
                          </div>
                          <div className="flex-1 min-w-0">
                            <p
                              className={cn(
                                'text-sm font-semibold truncate',
                                idx === 0 ? 'text-white' : 'text-white/80'
                              )}
                            >
                              {song.title}
                            </p>
                            <p className="text-xs text-white/45 truncate">{song.artist}</p>
                          </div>
                          <div className="flex flex-col items-end gap-1 flex-shrink-0">
                            <SongTime playedAt={song.played_at} />
                            {idx === 0 && (
                              <span className="text-[9px] font-bold text-[#f48fb1] bg-[#e91e63]/20 px-1.5 py-0.5 rounded-full border border-[#e91e63]/30">
                                AO VIVO
                              </span>
                            )}
                          </div>
                        </div>
                      ))}
                    </div>
                  )}
                </ScrollArea>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Footer Info */}
        <div className="mt-6 flex items-center gap-6 text-white/30 text-xs font-medium uppercase tracking-widest">
          <div className="flex items-center gap-2">
            <div className="w-1 h-1 bg-white/30 rounded-full" />
            ESTÉREO HD
          </div>
          <div className="flex items-center gap-2">
            <div className="w-1 h-1 bg-white/30 rounded-full" />
            CONEXÃO SEGURA
          </div>
        </div>
      </div>
      {/* end mobile-width wrapper */}

      <style jsx global>{`
        @keyframes progress {
          0% {
            background-position: 0 0;
          }
          100% {
            background-position: 40px 0;
          }
        }
        @keyframes pulse {
          0%,
          100% {
            opacity: 1;
          }
          50% {
            opacity: 0.3;
          }
        }
        .font-inter {
          font-family: 'Inter', sans-serif;
        }
      `}</style>
    </div>
  );
}
